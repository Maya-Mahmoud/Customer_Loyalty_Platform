<?php

namespace Database\Seeders;

use App\Enums\MerchantStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\LoyaltyRule;
use App\Models\Merchant;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Development data. Two merchants exist on purpose: the isolation required by
 * BRD FR-ADM-06 cannot be checked with only one.
 */
class DatabaseSeeder extends Seeder
{
    /** Development-only password, shared by every seeded account. */
    private const PASSWORD = 'password';

    public function run(): void
    {
        $plans = $this->seedPlans();

        $this->seedPlatformAdmin();

        $this->seedMerchant(
            plan: $plans['growth'],
            name: 'Al Noor Stores',
            register: 'CR-100200',
            email: 'owner@alnoor.test',
            city: 'Damascus',
            branches: [
                ['name' => 'Damascus Branch', 'city' => 'Damascus'],
                ['name' => 'Aleppo Branch', 'city' => 'Aleppo'],
            ],
            staffPrefix: 'alnoor',
        );

        $this->seedMerchant(
            plan: $plans['starter'],
            name: 'Zahra Boutique',
            register: 'CR-300400',
            email: 'owner@zahra.test',
            city: 'Latakia',
            branches: [
                ['name' => 'Main Branch', 'city' => 'Latakia'],
            ],
            staffPrefix: 'zahra',
        );

        $this->command->info('Seeded. Every account uses the password: '.self::PASSWORD);
    }

    /**
     * @return array<string, SubscriptionPlan>
     */
    private function seedPlans(): array
    {
        $definitions = [
            ['code' => 'starter', 'name' => 'Starter', 'max_branches' => 1, 'max_users' => 3, 'max_monthly_invoices' => 500, 'monthly_price' => 15],
            ['code' => 'growth', 'name' => 'Growth', 'max_branches' => 5, 'max_users' => 15, 'max_monthly_invoices' => 5000, 'monthly_price' => 45],
            ['code' => 'unlimited', 'name' => 'Unlimited', 'max_branches' => null, 'max_users' => null, 'max_monthly_invoices' => null, 'monthly_price' => 120],
        ];

        $plans = [];

        foreach ($definitions as $definition) {
            $plans[$definition['code']] = SubscriptionPlan::updateOrCreate(
                ['code' => $definition['code']],
                $definition,
            );
        }

        return $plans;
    }

    private function seedPlatformAdmin(): void
    {
        User::updateOrCreate(
            ['email' => $this->platformAdminEmail()],
            [
                'merchant_id' => null,
                'branch_id' => null,
                /*
                 * Arabic, because it is a name and not a label: it is printed
                 * wherever an account is named — the toolbar, the reviewer line on a
                 * merchant record, the audit trail — and no translation file can
                 * reach it there. The platform serves Syrian shops, so the account
                 * the platform ships with reads in their language. Whoever runs it
                 * can change it from their own profile screen.
                 */
                'name' => 'مشرف المنصة',
                'phone' => '0900000000',
                'password' => self::PASSWORD,
                'role' => UserRole::PlatformAdmin,
                'status' => UserStatus::Active,
            ],
        );
    }

    /**
     * @param  list<array{name: string, city: string}>  $branches
     */
    private function seedMerchant(
        SubscriptionPlan $plan,
        string $name,
        string $register,
        string $email,
        string $city,
        array $branches,
        string $staffPrefix,
    ): void {
        $merchant = Merchant::updateOrCreate(
            ['commercial_register' => $register],
            [
                'name' => $name,
                'trade_name' => $name,
                'owner_name' => 'Owner of '.$name,
                'email' => $email,
                'phone' => '0911111111',
                'city' => $city,
                'currency' => 'USD',
                'status' => MerchantStatus::Active,
                'status_changed_at' => now(),
                'activated_at' => now(),
                // An active account has necessarily been through verification and
                // review, so the seeded state reflects that (BRD FR-MER-02).
                'email_verified_at' => now(),
                'submitted_at' => now(),
                'reviewed_at' => now(),
                'subscription_plan_id' => $plan->id,
                'subscription_ends_at' => now()->addYear()->toDateString(),
            ],
        );

        $created = [];

        foreach ($branches as $branch) {
            $created[] = Branch::updateOrCreate(
                ['merchant_id' => $merchant->id, 'name' => $branch['name']],
                ['city' => $branch['city'], 'is_active' => true],
            );
        }

        $firstBranch = $created[0];

        // The owner spans every branch, so they are intentionally not tied to one.
        $this->staff($merchant->id, null, $email, 'Owner of '.$name, UserRole::MerchantOwner);

        $this->staff($merchant->id, $firstBranch->id, "manager@{$staffPrefix}.test", 'Branch Manager', UserRole::BranchManager);
        $this->staff($merchant->id, $firstBranch->id, "rep@{$staffPrefix}.test", 'Sales Rep', UserRole::SalesRep);

        // The defaults of BRD 11.1, so a fresh merchant already has a working rule.
        LoyaltyRule::updateOrCreate(
            ['merchant_id' => $merchant->id, 'version' => 1],
            LoyaltyRule::defaults() + [
                'effective_from' => now()->toDateString(),
                'is_active' => true,
            ],
        );
    }

    /**
     * Bounces from an unreachable supervisor address damage the sending account's
     * reputation, so this is configurable and should point at a real mailbox.
     */
    private function platformAdminEmail(): string
    {
        return env('SEED_PLATFORM_ADMIN_EMAIL', 'admin@platform.test');
    }

    private function staff(int $merchantId, ?int $branchId, string $email, string $name, UserRole $role): void
    {
        User::updateOrCreate(
            ['email' => $email],
            [
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'name' => $name,
                'phone' => '09'.random_int(10000000, 99999999),
                'password' => self::PASSWORD,
                'role' => $role,
                'status' => UserStatus::Active,
            ],
        );
    }
}
