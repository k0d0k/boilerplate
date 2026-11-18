<?php

namespace App\Providers;

use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\PermissionKey;
use App\Platform\Crud\BrandCrudDefinition;
use App\Platform\Health\Console\StagingBackupCommand;
use App\Platform\Health\Console\StagingHealthCommand;
use App\Platform\Health\Console\StagingLoginAssetsCommand;
use App\Support\RelativeUrlGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerRelativeUrlGenerator();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordResetNotifications();

        Gate::define(BrandCrudDefinition::ABILITY, [BrandCrudDefinition::class, 'allows']);

        // Every platform-scoped permission key resolves to a Gate, so the route
        // middleware (`can:...`), the sidebar `@can` directives and the
        // controllers all decide identically through the SINGLE principal
        // authorizer ({@see AuthorizesPrincipal}) — never from client state or
        // UI visibility. Tenant/clinical/patient keys are intentionally excluded:
        // those are resolved by the tenant RBAC layer, not here.
        //
        // This covers the Principal TailAdmin governance surfaces — Global
        // Settings (rbac.manage), Manajer Modul (module.manage), Paket Langganan
        // (plan.manage / subscription.view), Penggunaan & Token AI
        // (billing.manage / analytics.view), Hak Akses Fitur (entitlement.manage)
        // and Penyedia Pembayaran (payment.manage).
        foreach (PermissionKey::ALL as $platformPermission) {
            if (! str_starts_with($platformPermission, 'platform.')) {
                continue;
            }

            Gate::define($platformPermission, static function (?User $user) use ($platformPermission): bool {
                return $user instanceof User
                    && $user->is_active
                    && app(AuthorizesPrincipal::class)->can($user, $platformPermission);
            });
        }

        if ($this->app->runningInConsole()) {
            // WS5 — read-only staging health/diagnostic report.
            // WS6.F — read-only pre-deploy backup (central + tenant DBs, key, media).
            // WS6.G — post-deploy smoke check for the /login page's static assets.
            $this->commands([
                StagingHealthCommand::class,
                StagingBackupCommand::class,
                StagingLoginAssetsCommand::class,
            ]);
        }
    }

    /**
     * WS6.D — point Laravel's built-in password-reset notification at the
     * application's own localised, panel-less recovery route.
     *
     * The one-time token is only ever delivered here, to the account's OWN
     * email address; it is never returned to a caller and never written to a
     * log, so even an initiating Super Admin cannot learn or set the new
     * password. The message body carries no credential — only the short-lived
     * link and its expiry.
     */
    protected function configurePasswordResetNotifications(): void
    {
        $resetUrl = static fn (User $notifiable, string $token): string => route('staff.password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        ResetPassword::createUrlUsing($resetUrl);

        ResetPassword::toMailUsing(static fn (User $notifiable, string $token): MailMessage => (new MailMessage)
            ->subject(__('lba.password_reset.mail_subject'))
            ->line(__('lba.password_reset.mail_intro'))
            ->action(__('lba.password_reset.mail_action'), $resetUrl($notifiable, $token))
            ->line(__('lba.password_reset.mail_expiry', [
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]))
            ->line(__('lba.password_reset.mail_footer')));
    }

    /**
     * Replace the URL generator with one that emits root-relative URLs.
     *
     * Mirrors Illuminate\Routing\RoutingServiceProvider::registerUrlGenerator()
     * but uses RelativeUrlGenerator so Filament/Livewire assets stay same-origin
     * when the app runs behind a proxy that does not forward the real host
     * (see App\Support\RelativeUrlGenerator).
     */
    protected function registerRelativeUrlGenerator(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            $generator = new RelativeUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url'],
            );

            // Publish the generator to the container *before* assigning the
            // route collection. `instance('routes', ...)` fires the framework's
            // "routes" rebound callback, which re-resolves `url`; without this
            // pre-registration the generator would still be mid-build and the
            // callback would re-enter the factory until the stack overflows.
            $app->instance('url', $generator);

            $app->instance('routes', $routes);

            return $generator;
        });
    }
}
