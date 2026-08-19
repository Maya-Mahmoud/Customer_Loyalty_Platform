<?php

namespace Database\Factories;

use App\Enums\MerchantStatus;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'trade_name' => fake()->company(),
            'commercial_register' => 'CR-'.fake()->unique()->numerify('########'),
            'owner_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('########'),
            'city' => fake()->city(),
            'currency' => 'USD',
            'status' => MerchantStatus::Active,
            'status_changed_at' => now(),
            'activated_at' => now(),
            'email_verified_at' => now(),
            'submitted_at' => now(),
        ];
    }

    /** Submitted but the email is not yet proven, so not reviewable. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => MerchantStatus::Pending,
            'activated_at' => null,
            'email_verified_at' => null,
            'phone_verified_at' => null,
            'submitted_at' => null,
        ]);
    }

    /**
     * Verified and waiting in the supervisor's queue. The phone stays unverified,
     * which is the real post-registration state.
     */
    public function awaitingReview(): static
    {
        return $this->pending()->state(fn () => [
            'email_verified_at' => now(),
            'submitted_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => MerchantStatus::Rejected,
            'status_reason' => 'Commercial register could not be confirmed',
            'activated_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => MerchantStatus::Suspended,
            'status_reason' => 'Unpaid subscription',
            'status_changed_at' => now(),
        ]);
    }
}
