<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\StaffLoginTest;

/**
 * Drives the unified login page exactly the way a browser does.
 *
 * The staff and patient logins are Filament pages that submit through Livewire,
 * so the flow is exercised over HTTP: fetch the rendered page, then POST the
 * Livewire snapshot back to the update endpoint with the form state applied.
 * This is the same technique used by {@see StaffLoginTest};
 * it lives in a trait so entry-point navigation tests can replay the real login
 * before asserting on the destination page.
 *
 * The consuming test must seed a synthetic identity whose password is
 * {@see self::SYNTHETIC_LOGIN_PASSWORD} (the factory default).
 */
trait SubmitsTheLoginForm
{
    protected const SYNTHETIC_LOGIN_PASSWORD = 'password';

    /** Render a Livewire-backed login page and return its markup. */
    protected function renderLoginPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    /**
     * Submit a rendered login page's form the way the browser does.
     *
     * @param  array<string, mixed>  $extraState  additional top-level form state keys
     */
    protected function submitLoginForm(string $html, string $email, string $password, array $extraState = []): TestResponse
    {
        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => array_merge([
                    'data.email' => $email,
                    'data.password' => $password,
                ], $extraState),
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /** Convenience wrapper: render the page, then submit it. */
    protected function loginThrough(
        string $path,
        string $email,
        string $password = self::SYNTHETIC_LOGIN_PASSWORD,
        array $extraState = [],
    ): TestResponse {
        return $this->submitLoginForm($this->renderLoginPage($path), $email, $password, $extraState);
    }

    /** The panel the login response redirects to, if it authenticated. */
    protected function loginRedirect(TestResponse $response): ?string
    {
        return $response->json('components.0.effects.redirect');
    }
}
