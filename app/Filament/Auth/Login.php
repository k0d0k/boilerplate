<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * LBA Cloud premium login presentation.
 *
 * Extends Filament's supported authentication page: the authentication flow,
 * rate limiting, MFA handling and `canAccessPanel()` enforcement are inherited
 * unchanged. Only presentation (layout, copy, field labels) is customised.
 *
 * A password-recovery link is rendered next to the password field. It points at
 * LBA Cloud's own panel-less, throttled recovery flow (`staff.password.forgot`),
 * NOT Filament's panel password-reset pages, so a single hardened route serves
 * every human identity (Principal, Clinic and Patient).
 */
class Login extends BaseLogin
{
    protected static string $layout = 'filament.lba.auth.layout';

    public function hasLogo(): bool
    {
        return false;
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.auth.title');
    }

    public function getHeading(): string|Htmlable
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return __('lba.auth.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        return __('lba.auth.subheading');
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->label(__('lba.auth.email'))
            ->placeholder(__('lba.auth.email_placeholder'));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label(__('lba.auth.password'))
            ->hint(new HtmlString(Blade::render(
                '<x-filament::link :href="route(\'staff.password.forgot\')" tabindex="-1">{{ __(\'lba.password_reset.forgot_title\') }}</x-filament::link>'
            )))
            ->revealable();
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->label(__('lba.auth.remember'));
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->label(__('lba.auth.submit'));
    }
}
