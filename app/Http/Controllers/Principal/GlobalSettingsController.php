<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Localization\LocaleResolver;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Cpanel\CpanelConnectionProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 3 — Principal "Pengaturan Global" (Global Settings) TailAdmin screen.
 *
 * One screen with two tabs:
 *  - "Umum & Bahasa" — the persisted platform default language, which
 *    {@see LocaleResolver} consumes as its last-resort
 *    default (the account → brand → tenant priority order is unchanged);
 *  - "Hosting & Database Tenant" — the EFFECTIVE cPanel configuration, a
 *    WRITE-ONLY API token and a read-only connection probe.
 *
 * SECURITY
 *  - Every action re-derives authorization on the SERVER (never from UI
 *    visibility) and requires an ACTIVE principal holding
 *    `platform.rbac.manage` — in the shipped matrix only the Principal Super
 *    Admin role — matching the route-level gate.
 *  - The cPanel API token is write-only: a blank submission keeps the stored
 *    value, it is persisted ENCRYPTED, and it is never returned in a response,
 *    a view, a log line or the audit trail (the audit names only the setting).
 */
final class GlobalSettingsController extends Controller
{
    use AuthorizesPrincipalResources;

    /** @var list<string> */
    private const TABS = ['general', 'hosting'];

    public function index(Request $request, PlatformSettings $settings): View
    {
        $this->authorizeSettings();

        $tab = $request->string('tab')->toString();

        return view('principal.settings.index', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'general',
            'systemName' => $settings->systemName(),
            'locale' => $settings->localeDefault(),
            'supportedLocales' => $settings->supportedLocales(),
            'localeLabels' => (array) config('localization.labels', []),
            'cpanel' => $settings->cpanelDisplay(),
            'databaseMode' => $settings->databaseMode(),
            'manualServer' => $settings->manualServer(),
            'probe' => $request->session()->get('cpanel_probe'),
        ]);
    }

    public function updateGeneral(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'system_name' => ['required', 'string', 'max:255'],
            'default_locale' => ['required', 'string', Rule::in($settings->supportedLocales())],
        ]);

        $settings->setSystemName($data['system_name'], $this->actor());
        $settings->setLocaleDefault($data['default_locale'], $this->actor());

        return to_route('principal.tailadmin.settings.index', ['tab' => 'general'])
            ->with('success', __('principal.settings.general.saved'));
    }

    public function updateCpanel(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $this->authorizeSettings();

        $data = $request->validate([
            'mode' => ['nullable', Rule::in([PlatformSettings::MODE_CPANEL, PlatformSettings::MODE_MANUAL])],
            'enabled' => ['nullable', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:64'],
            'runtime_user' => ['nullable', 'string', 'max:64'],
            'verify_tls' => ['nullable', 'boolean'],
            'timeout' => ['nullable', 'integer', 'between:1,300'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($data['mode'])) {
            $settings->setDatabaseMode((string) $data['mode'], $this->actor());
        }

        // The token is handed straight to the settings service, which encrypts
        // it and keeps the current value when the field is left blank. It is
        // never flashed, logged or echoed back to the client.
        $settings->updateCpanel([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'host' => (string) ($data['host'] ?? ''),
            'port' => (int) ($data['port'] ?? 2083),
            'username' => (string) ($data['username'] ?? ''),
            'runtime_user' => (string) ($data['runtime_user'] ?? ''),
            'verify_tls' => (bool) ($data['verify_tls'] ?? false),
            'timeout' => (int) ($data['timeout'] ?? 20),
            'token' => (string) ($data['token'] ?? ''),
        ], $this->actor());

        return to_route('principal.tailadmin.settings.index', ['tab' => 'hosting'])
            ->with('success', __('principal.settings.hosting.saved'));
    }

    public function testConnection(CpanelConnectionProbe $probe): RedirectResponse
    {
        $this->authorizeSettings();

        return to_route('principal.tailadmin.settings.index', ['tab' => 'hosting'])
            ->with('cpanel_probe', $probe->run());
    }

    private function authorizeSettings(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_RBAC_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
