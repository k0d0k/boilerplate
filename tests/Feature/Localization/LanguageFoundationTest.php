<?php

namespace Tests\Feature\Localization;

use App\Localization\Contracts\ResolvesLocale;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsAccess;
use Tests\TestCase;

/**
 * Phase 1B-3 — multi-language & localization foundation (STEP 8).
 *
 * Covers the approved locale resolution order, the three canonical languages,
 * the secure user preference, the authorization boundaries language must never
 * touch, and the existing login/dashboard/asset regressions.
 *
 * Nothing here trusts the UI: locale resolution is asserted against the
 * resolver, and the selector is driven through Livewire's real update endpoint
 * exactly like the browser does.
 */
class LanguageFoundationTest extends TestCase
{
    use BuildsAccess, RefreshDatabase;

    private const SYNTHETIC_PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function resolver(): ResolvesLocale
    {
        return app(ResolvesLocale::class);
    }

    private function context(): ManagesTenantContext
    {
        return app(ManagesTenantContext::class);
    }

    /** A clinic identity holding one active membership in $tenant. */
    private function clinicIn(Tenant $tenant, ?string $locale = null): User
    {
        $factory = User::factory()->role(RoleKey::TENANT_OWNER);

        if ($locale !== null) {
            $factory = $factory->locale($locale);
        }

        $user = $factory->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /** Render an authenticated panel page and return its markup. */
    private function renderPage(string $path): string
    {
        return $this->get($path)->assertOk()->getContent();
    }

    /** Extract a component's `wire:snapshot` from rendered page markup. */
    private function componentSnapshot(string $html, string $component): string
    {
        if (! preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The rendered page did not expose any Livewire snapshot.');
        }

        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
                return $snapshot;
            }
        }

        $this->fail("The rendered page did not expose a [{$component}] component.");
    }

    /**
     * Drive Livewire's real update endpoint with a single method call, so the
     * `web`-group middleware (including SetLocale) is replayed.
     *
     * @param  list<mixed>  $params
     */
    private function callLivewire(string $html, string $component, string $method, array $params = []): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $this->componentSnapshot($html, $component),
                'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
                'updates' => [],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /** The re-rendered markup returned by a Livewire update response. */
    private function renderedHtml(TestResponse $response): string
    {
        return (string) $response->json('components.0.effects.html');
    }

    /** Submit a rendered login page's form the way the browser does. */
    private function login(string $email, string $password = self::SYNTHETIC_PASSWORD): TestResponse
    {
        $html = $this->renderPage('/login');

        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => ['data.email' => $email, 'data.password' => $password],
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. All three locale choices — each resolves its own catalog
    |--------------------------------------------------------------------------
    */

    public function test_all_three_supported_locales_are_canonical_and_ordered(): void
    {
        $this->assertSame(['id', 'ms-MY', 'en'], $this->resolver()->supported());
        $this->assertSame('id', $this->resolver()->default());
    }

    public function test_each_locale_resolves_its_own_translation(): void
    {
        $titles = [
            'id' => 'Dasbor Klinik',
            'ms-MY' => 'Papan Pemuka Klinik',
            'en' => 'Clinic Dashboard',
        ];

        foreach ($titles as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, __('lba.dashboard.clinic_title'), "Locale [{$locale}]");
        }
    }

    public function test_malaysian_malay_never_substitutes_indonesian(): void
    {
        // Filament chrome resolves ms-MY through its shipped `ms` catalog...
        app()->setLocale('ms-MY');
        $this->assertSame('Papan pemuka', __('filament-panels::pages/dashboard.title'));

        // ...and the app catalog through ms-MY, distinct from the Indonesian one.
        app()->setLocale('id');
        $this->assertSame('Dasbor', __('filament-panels::pages/dashboard.title'));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Default locale resolution
    |--------------------------------------------------------------------------
    */

    public function test_the_platform_falls_back_to_indonesian(): void
    {
        $this->assertSame('id', $this->resolver()->resolve(null));
        $this->assertSame('id', $this->resolver()->resolve(User::factory()->create()));
    }

    /*
    |--------------------------------------------------------------------------
    | 3. User preference precedence
    |--------------------------------------------------------------------------
    */

    public function test_the_saved_user_preference_wins_over_brand_and_tenant(): void
    {
        $tenant = Tenant::factory()->create(['metadata' => ['default_locale' => 'id']]);
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'nubiom']), ['is_primary' => true]);

        $user = $this->clinicIn($tenant, 'en');

        $this->assertSame('en', $this->resolver()->resolve($user));
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Brand and tenant fallback
    |--------------------------------------------------------------------------
    */

    public function test_the_shipped_brand_default_is_used_without_a_user_preference(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'nubiom']), ['is_primary' => true]);

        $this->assertSame('ms-MY', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    public function test_a_stored_brand_default_overrides_the_shipped_starter_default(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach(
            Brand::factory()->create(['slug' => 'nubiom', 'metadata' => ['default_locale' => 'en']]),
            ['is_primary' => true],
        );

        $this->assertSame('en', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    public function test_babul_syiffa_defaults_to_indonesian(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'babul-syiffa']), ['is_primary' => true]);

        $this->assertSame('id', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    public function test_the_verified_tenant_default_is_used_when_no_brand_governs(): void
    {
        $tenant = Tenant::factory()->create(['metadata' => ['default_locale' => 'en']]);

        $this->assertSame('en', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    public function test_a_brand_without_a_stored_default_falls_through_to_the_tenant_default(): void
    {
        // Biodam has neither a stored setting nor a shipped default: no value is
        // invented, so resolution falls through to the tenant default.
        $tenant = Tenant::factory()->create(['metadata' => ['default_locale' => 'en']]);
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'biodam']), ['is_primary' => true]);

        $this->assertSame('en', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Ambiguous brand handling
    |--------------------------------------------------------------------------
    */

    public function test_an_ambiguous_brand_context_never_picks_a_brand_language(): void
    {
        // Two active brands, neither designated primary → ambiguous. The brand
        // defaults (nubiom → ms-MY) must NOT be chosen arbitrarily.
        $tenant = Tenant::factory()->create(['metadata' => ['default_locale' => 'en']]);
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'nubiom']), ['is_primary' => false]);
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'babul-syiffa']), ['is_primary' => false]);

        $this->assertSame('en', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    public function test_an_ambiguous_brand_context_without_a_tenant_default_uses_the_platform_default(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'nubiom']), ['is_primary' => false]);
        $tenant->brands()->attach(Brand::factory()->create(['slug' => 'babul-syiffa']), ['is_primary' => false]);

        $this->assertSame('id', $this->resolver()->resolve($this->clinicIn($tenant)));
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Unsupported locale rejection
    |--------------------------------------------------------------------------
    */

    public function test_unsupported_locale_values_are_never_accepted(): void
    {
        foreach (['fr', 'ms', 'ID', '', 'en-US', 'id_ID'] as $value) {
            $this->assertFalse($this->resolver()->isSupported($value), "[{$value}] must be rejected");
        }

        // A crafted value already persisted on the record is ignored on read.
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->locale('fr')->create();
        $this->giveMembership($user, Tenant::factory()->create());

        $this->assertSame('id', $this->resolver()->resolve($user));
    }

    public function test_the_selector_endpoint_rejects_an_unsupported_locale(): void
    {
        $user = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->actingAs($user);

        $this->callLivewire($this->renderPage('/principal'), 'language-switcher', 'setLocale', ['fr'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->locale);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Preference persistence across login & logout
    |--------------------------------------------------------------------------
    */

    public function test_the_preference_persists_across_login_and_logout(): void
    {
        $user = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->locale('en')->create();

        $response = $this->login($user->email);
        $response->assertOk();
        $this->assertSame('/principal', $response->json('components.0.effects.redirect'));

        // The saved preference governs the freshly authenticated request.
        $this->get('/principal')->assertOk()->assertSee('Platform Overview');

        $this->post('/principal/logout')->assertRedirect();
        $this->assertGuest();
        $this->assertSame('en', $user->fresh()->locale);

        // Signing back in resolves the same persisted preference.
        $this->login($user->email);
        $this->get('/principal')->assertOk()->assertSee('Platform Overview');
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Language retention across tenant switching
    |--------------------------------------------------------------------------
    */

    public function test_the_language_is_retained_when_switching_tenants(): void
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->locale('ms-MY')->create();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->giveMembership($user, $a, [RoleKey::TENANT_OWNER]);
        $this->giveMembership($user, $b, [RoleKey::TENANT_OWNER]);

        $this->actingAs($user);
        $this->assertTrue($this->context()->select($user, $b->getKey()));

        // The preference is independent of the tenant context.
        $this->assertSame('ms-MY', $this->resolver()->resolve($user->fresh()));
        $this->get('/clinic')->assertOk()->assertSee('Papan Pemuka Klinik');
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Panel authorization is unchanged by language
    |--------------------------------------------------------------------------
    */

    public function test_principal_clinic_and_patient_authorization_is_unchanged(): void
    {
        foreach (['id', 'ms-MY', 'en'] as $locale) {
            $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->locale($locale)->create();
            $clinic = $this->clinicIn(Tenant::factory()->create(), $locale);

            // Phase 8A: the patient panel now requires a verified tenant
            // context, so the identity must hold an active membership.
            $patient = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->locale($locale)->create();
            $this->giveMembership($patient, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);

            $this->actingAs($principal)->get('/principal')->assertOk();
            $this->actingAs($clinic)->get('/clinic')->assertOk();
            $this->actingAs($patient)->get('/patient')->assertOk();

            // Cross-panel access stays denied in every language.
            $this->actingAs($principal)->get('/clinic')->assertForbidden();
            $this->actingAs($principal)->get('/patient')->assertForbidden();
            $this->actingAs($clinic)->get('/principal')->assertForbidden();
            $this->actingAs($patient)->get('/principal')->assertForbidden();
            $this->actingAs($patient)->get('/clinic')->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Revoked & suspended memberships still denied
    |--------------------------------------------------------------------------
    */

    public function test_revoked_and_suspended_memberships_are_still_denied(): void
    {
        foreach (['id', 'ms-MY', 'en'] as $locale) {
            $revoked = User::factory()->role(RoleKey::TENANT_OWNER)->locale($locale)->create();
            $this->giveMembership($revoked, Tenant::factory()->create(), [RoleKey::TENANT_OWNER], TenantMembership::STATUS_REVOKED);
            $this->actingAs($revoked)->get('/clinic')->assertForbidden();

            $suspended = User::factory()->role(RoleKey::TENANT_OWNER)->locale($locale)->create();
            $this->giveMembership($suspended, Tenant::factory()->create(), [RoleKey::TENANT_OWNER], TenantMembership::STATUS_SUSPENDED);
            $this->actingAs($suspended)->get('/clinic')->assertForbidden();

            // A suspended identity itself is denied regardless of language.
            $disabled = User::factory()->role(RoleKey::TENANT_OWNER)->locale($locale)->suspended()->create();
            $this->giveMembership($disabled, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);
            $this->actingAs($disabled)->get('/clinic')->assertForbidden();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Livewire requests use the correct locale
    |--------------------------------------------------------------------------
    */

    public function test_livewire_requests_resolve_the_saved_locale(): void
    {
        $user = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->locale('en')->create();
        $this->actingAs($user);

        $response = $this->callLivewire($this->renderPage('/principal'), 'language-switcher', 'toggle');
        $response->assertOk();

        // The re-render happened under the saved locale on the real endpoint.
        $this->assertStringContainsString('Choose a language', $this->renderedHtml($response));
    }

    public function test_the_selector_persists_the_choice_on_the_real_endpoint(): void
    {
        $user = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->actingAs($user);

        $html = $this->renderPage('/principal');

        $response = $this->callLivewire($html, 'language-switcher', 'setLocale', ['ms-MY']);
        $response->assertOk();
        $this->assertSame('ms-MY', $user->fresh()->locale);
        $this->assertSame('/principal', $response->json('components.0.effects.redirect'));

        // The next real request now renders Malaysian Malay. The language
        // selector's own label is a distinct `lba` catalog string in each
        // locale ("Tukar bahasa antara muka" for ms-MY only), so seeing it
        // proves the shell resolved ms-MY rather than the platform default.
        $this->get('/principal')->assertOk()->assertSee('Tukar bahasa antara muka');
    }

    /*
    |--------------------------------------------------------------------------
    | STEP 7 — regional settings are independent of language
    |--------------------------------------------------------------------------
    */

    public function test_language_never_alters_regional_settings(): void
    {
        // The localization config deliberately carries no currency, timezone,
        // payment, jurisdiction or database settings.
        foreach (['currency', 'timezone', 'payment_gateway', 'jurisdiction'] as $key) {
            $this->assertArrayNotHasKey($key, config('localization'));
        }

        $tenant = Tenant::factory()->create([
            'name' => 'Mitra Nusantara',
            'metadata' => ['timezone' => 'Asia/Jakarta', 'currency' => 'IDR'],
        ]);
        $user = $this->clinicIn($tenant, 'id');
        $this->actingAs($user);

        foreach (['id', 'ms-MY', 'en'] as $locale) {
            $this->callLivewire($this->renderPage('/clinic'), 'language-switcher', 'setLocale', [$locale])
                ->assertOk();

            // Tenant identity and its independently configured regional metadata
            // are untouched by a display-language change.
            $fresh = $tenant->fresh();
            $this->assertSame('Mitra Nusantara', $fresh->name);
            $this->assertSame(Tenant::STATUS_ACTIVE, $fresh->status);
            $this->assertSame('Asia/Jakarta', $fresh->metadata['timezone']);
            $this->assertSame('IDR', $fresh->metadata['currency']);
        }

        // The verified tenant context is also unchanged by language switching.
        $this->assertSame($tenant->getKey(), $this->context()->currentTenantId($user->fresh()));
    }

    /*
    |--------------------------------------------------------------------------
    | 12. No missing required translation keys
    |--------------------------------------------------------------------------
    */

    public function test_no_required_translation_keys_are_missing(): void
    {
        $this->artisan('lba:localization:audit')->assertExitCode(0);
        $this->artisan('lba:localization:audit', ['--strict' => true])->assertExitCode(0);

        // A representative slice of the contract keys resolves in every locale.
        $keys = [
            'lba.auth.title',
            'lba.dashboard.principal_title',
            'lba.nav.preview_group',
            'lba.language.choose',
            'lba.switcher.choose',
            'lba.select_tenant.page_title',
            'lba.examination.mode_manual',
        ];

        foreach (['id', 'ms-MY', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key), "Missing [{$key}] for [{$locale}]");
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 13. Existing login / dashboard / asset regressions
    |--------------------------------------------------------------------------
    */

    public function test_login_and_dashboards_still_render(): void
    {
        app()->setLocale('id');

        $this->get('/login')->assertOk()->assertSee(__('lba.auth.heading'));

        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();
        $this->actingAs($principal)->get('/principal')->assertOk()->assertSee(__('lba.dashboard.principal_title'));

        $clinic = $this->clinicIn(Tenant::factory()->create());
        $this->actingAs($clinic)->get('/clinic')->assertOk()->assertSee(__('lba.dashboard.clinic_title'));

        $patient = User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create();
        $this->giveMembership($patient, Tenant::factory()->create(), [RoleKey::PATIENT_CUSTOMER]);
        $this->actingAs($patient)->get('/patient')->assertOk()->assertSee(__('lba.dashboard.patient_title'));
    }

    public function test_the_premium_theme_and_js_assets_are_still_built(): void
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertArrayHasKey('resources/css/filament/lba-cloud/theme.css', $manifest);
        $this->assertArrayHasKey('resources/js/app.js', $manifest);

        // The language selector styles ship inside the panel theme entry.
        $theme = (string) file_get_contents(
            public_path('build/'.$manifest['resources/css/filament/lba-cloud/theme.css']['file']),
        );

        $this->assertStringContainsString('.lba-lang__trigger', $theme);
        $this->assertStringContainsString('.lba-lang__option', $theme);
    }

    public function test_the_language_selector_is_rendered_exactly_once_per_page(): void
    {
        $principal = User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create();

        $html = $this->actingAs($principal)->get('/principal')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'wire:key="lba-language-switcher"'));
    }
}
