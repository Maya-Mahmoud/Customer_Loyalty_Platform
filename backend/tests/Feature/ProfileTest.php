<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The signed-in user's own account, and the store's own profile.
 *
 * Two themes run through the cases. Nothing here takes a user id, so no request
 * shape edits another account — a profile screen that could raise its own role would
 * make the matrix of BRD 7.2 advisory. And an uploaded image is never stored as it
 * arrived: what lands on disk is bytes this application encoded.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Branch $branch;

    private User $owner;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->merchant = Merchant::factory()->create([
            'trade_name' => 'Old Trade Name',
            'city' => 'Damascus',
            'currency' => 'USD',
        ]);
        $this->branch = Branch::factory()->for($this->merchant)->create();

        $this->owner = User::factory()->owner($this->merchant->id)->create([
            'name' => 'Owner',
            'password' => Hash::make('current-password-1'),
        ]);

        $this->rep = User::factory()->salesRep($this->branch)->create([
            'password' => Hash::make('current-password-1'),
        ]);
    }

    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        // A real 400x300 JPEG, so GD has something to decode rather than a fake.
        return UploadedFile::fake()->image($name, 400, 300);
    }

    // -----------------------------------------------------------------
    // Name and phone
    // -----------------------------------------------------------------

    public function test_a_user_edits_their_own_name_and_phone(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Sami the Rep',
                'phone' => '0991112223',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Sami the Rep')
            ->assertJsonPath('user.phone', '0991112223');

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'profile.updated')->exists()
        );
    }

    public function test_the_role_and_email_cannot_be_changed_through_the_profile(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Sami',
                // Both ignored rather than rejected: they are not fields of this form.
                'role' => 'merchant_owner',
                'email' => 'someone-else@example.test',
                'merchant_id' => 999,
            ])
            ->assertOk();

        $fresh = $this->rep->fresh();
        $this->assertSame('sales_rep', $fresh->role->value);
        $this->assertSame($this->rep->email, $fresh->email);
        $this->assertSame($this->merchant->id, $fresh->merchant_id);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // -----------------------------------------------------------------
    // Password (FR-SEC-01)
    // -----------------------------------------------------------------

    public function test_a_user_changes_their_password_with_the_current_one(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'current-password-1',
                'password' => 'a-new-password-9',
                'password_confirmation' => 'a-new-password-9',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-new-password-9', $this->rep->fresh()->password));

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'profile.password_changed')->exists()
        );
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'not-the-password',
                'password' => 'a-new-password-9',
                'password_confirmation' => 'a-new-password-9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        // An unlocked till left on the counter is not enough to take an account over.
        $this->assertTrue(Hash::check('current-password-1', $this->rep->fresh()->password));
    }

    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'current-password-1',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'current-password-1',
                'password' => 'a-new-password-9',
                'password_confirmation' => 'a-different-one-9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_changing_a_password_signs_other_devices_out_but_not_this_one(): void
    {
        // Two devices signed in; the request comes from the second.
        $phone = $this->rep->createToken('phone');
        $till = $this->rep->createToken('till');

        $this->withHeader('Authorization', 'Bearer ' . $till->plainTextToken)
            ->putJson('/api/v1/auth/profile/password', [
                'current_password' => 'current-password-1',
                'password' => 'a-new-password-9',
                'password_confirmation' => 'a-new-password-9',
            ])
            ->assertOk();

        /*
         * If the reason for the change was that somebody else had the password,
         * leaving their session alive would defeat it — and throwing the user out of
         * the screen they are standing on would be its own kind of rude.
         */
        $remaining = $this->rep->tokens()->pluck('id');
        $this->assertTrue($remaining->contains($till->accessToken->id));
        $this->assertFalse($remaining->contains($phone->accessToken->id));

        // The guard caches the user it resolved for the previous request inside one
        // test process; a real second request would arrive with nothing resolved.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $phone->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // The picture
    // -----------------------------------------------------------------

    public function test_a_user_uploads_a_picture_and_gets_a_url_back(): void
    {
        $response = $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', ['image' => $this->image()])
            ->assertOk();

        $path = $this->rep->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // Re-encoded, so the stored file is a JPEG this application wrote whatever
        // the upload was named.
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertNotNull($response->json('user.avatar_url'));
    }

    public function test_a_large_picture_is_scaled_down(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', [
                'image' => UploadedFile::fake()->image('huge.jpg', 2000, 1000),
            ])
            ->assertOk();

        $stored = Storage::disk('public')->get($this->rep->fresh()->avatar_path);
        [$width, $height] = getimagesizefromstring($stored);

        // The longer side is capped; the shape is kept.
        $this->assertSame(512, $width);
        $this->assertSame(256, $height);
    }

    public function test_replacing_a_picture_removes_the_old_file(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', ['image' => $this->image('first.jpg')])
            ->assertOk();

        $first = $this->rep->fresh()->avatar_path;

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', ['image' => $this->image('second.jpg')])
            ->assertOk();

        $second = $this->rep->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        // Ten changes leave one file behind, not ten.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_picture_can_be_removed(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', ['image' => $this->image()])
            ->assertOk();

        $path = $this->rep->fresh()->avatar_path;

        $this->actingAs($this->rep, 'sanctum')
            ->deleteJson('/api/v1/auth/profile/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->rep->fresh()->avatar_path);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', [
                'image' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        // An SVG is a document that can carry script, not a picture, and it cannot
        // be re-encoded into pixels.
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', [
                'image' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
            ])
            ->assertStatus(422);

        $this->assertNull($this->rep->fresh()->avatar_path);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_upload_is_refused(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/auth/profile/avatar', [
                'image' => UploadedFile::fake()->create('huge.jpg', 4096, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    // -----------------------------------------------------------------
    // The store profile (FR-MER-05, FR-MER-06)
    // -----------------------------------------------------------------

    public function test_the_owner_edits_the_store_details(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/store', [
                'trade_name' => 'New Trade Name',
                'city' => 'Aleppo',
                'phone' => '0111234567',
                // FR-MER-05: lower case is accepted and normalised.
                'currency' => 'syp',
            ])
            ->assertOk()
            ->assertJsonPath('data.trade_name', 'New Trade Name')
            ->assertJsonPath('data.currency', 'SYP');

        $this->assertTrue(
            AuditLog::withoutGlobalScopes()->where('action', 'merchant.profile_updated')->exists()
        );
    }

    public function test_the_registered_name_and_register_number_are_not_editable(): void
    {
        $original = $this->merchant->only(['name', 'commercial_register', 'email']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/v1/store', [
                'city' => 'Aleppo',
                'phone' => '0111234567',
                'currency' => 'USD',
                'name' => 'A Different Company',
                'commercial_register' => '99999',
                'email' => 'new@example.test',
            ])
            ->assertOk();

        // These identify the business and were verified at registration (BRD 8.1).
        $fresh = $this->merchant->fresh();
        $this->assertSame($original['name'], $fresh->name);
        $this->assertSame($original['commercial_register'], $fresh->commercial_register);
        $this->assertSame($original['email'], $fresh->email);
    }

    public function test_the_owner_uploads_and_removes_the_store_logo(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/store/logo', ['image' => $this->image('logo.png')])
            ->assertOk()
            ->assertJsonPath('data.logo_url', fn ($url) => $url !== null);

        $path = $this->merchant->fresh()->logo_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/v1/store/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_rep_cannot_touch_the_store_profile(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->putJson('/api/v1/store', [
                'city' => 'Aleppo',
                'phone' => '0111234567',
                'currency' => 'USD',
            ])
            ->assertForbidden();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/v1/store/logo', ['image' => $this->image()])
            ->assertForbidden();

        $this->assertSame('Damascus', $this->merchant->fresh()->city);
    }

    public function test_an_owner_only_ever_edits_their_own_store(): void
    {
        $other = Merchant::factory()->create(['city' => 'Homs']);
        $otherOwner = User::factory()->owner($other->id)->create();

        // There is no id in the path at all: the store comes from the pinned tenant.
        $this->actingAs($otherOwner, 'sanctum')
            ->putJson('/api/v1/store', [
                'city' => 'Latakia',
                'phone' => '0111234567',
                'currency' => 'USD',
            ])
            ->assertOk();

        $this->assertSame('Latakia', $other->fresh()->city);
        $this->assertSame('Damascus', $this->merchant->fresh()->city);
    }
}
