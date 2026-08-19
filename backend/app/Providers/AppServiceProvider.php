<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\NullSmsGateway;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, so the global merchant scope and the
        // controllers always agree on which tenant is in scope.
        $this->app->singleton(TenantContext::class);

        $this->bindSmsGateway();
    }

    public function boot(): void
    {
        $this->registerPermissionGates();

        // Catches a mistyped attribute at development time instead of letting it
        // silently disappear on save.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    /**
     * Turns every row of the BRD 7.2 matrix into a gate, so both route
     * middleware (`can:invoices.create`) and imperative checks read the same
     * source of truth.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->hasPermission($permission),
            );
        }
    }

    /**
     * No SMS provider is contracted yet and the channel is still an open decision
     * (BRD 5.5, OD-08), so the driver is configuration. Adding a real provider
     * means one new class implementing SmsGateway and one line here.
     */
    private function bindSmsGateway(): void
    {
        $this->app->singleton(SmsGateway::class, fn () => match (config('sms.driver')) {
            'null' => new NullSmsGateway(),
            default => new LogSmsGateway(),
        });
    }
}
