<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Defaults to a merchant owner with no merchant, because every test that
     * needs one is explicit about which merchant it belongs to.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::MerchantOwner,
            'status' => UserStatus::Active,
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::PlatformAdmin,
            'merchant_id' => null,
            'branch_id' => null,
        ]);
    }

    public function owner(int $merchantId): static
    {
        return $this->state(fn () => [
            'role' => UserRole::MerchantOwner,
            'merchant_id' => $merchantId,
            'branch_id' => null,
        ]);
    }

    public function branchManager(Branch $branch): static
    {
        return $this->state(fn () => [
            'role' => UserRole::BranchManager,
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function salesRep(Branch $branch): static
    {
        return $this->state(fn () => [
            'role' => UserRole::SalesRep,
            'merchant_id' => $branch->merchant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Invited,
            'password' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Disabled]);
    }
}
