<?php

namespace Modules\Commerce\Providers;

use Modules\Commerce\Payments\Contracts\PaymentGatewayDriver;
use Modules\Commerce\Payments\PaymentManager;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * WS3 — Commerce module service provider.
 *
 * The base class loads the module's translations (`commerce::…`), views
 * (`commerce::…`) and optional config. Every tenant table the module owns lives
 * in the shared tenant migration path (`database/migrations/tenant`), which is
 * the only path the trusted TenantMigrator applies to a tenant's isolated
 * database — the module ships no migrations of its own.
 *
 * The payment subsystem is bound as a single manager that resolves the concrete
 * {@see PaymentGatewayDriver} for a configured gateway, so no caller ever
 * instantiates a driver directly (no duplicate services).
 */
class CommerceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Commerce';

    protected string $nameLower = 'commerce';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PaymentManager::class);
    }
}
