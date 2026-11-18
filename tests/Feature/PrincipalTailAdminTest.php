<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Access\SupportTenantSession;
use App\Platform\Crud\BrandCrudDefinition;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;

class PrincipalTailAdminTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_tailadmin_shell_exposes_a_single_governance_entry_and_a_top_right_account_menu(): void
    {
        $actor = $this->principal();
        $actor->forceFill(['name' => 'Rina Wijaya'])->save();

        $html = $this->actingAs($actor)->get('/principal/master-brands')->assertOk()->getContent();

        $labels = $this->principalLinkLabels($html);

        // The governance group leads with one canonical "Brand & Mitra" entry
        // (once for the desktop rail, once for the mobile drawer). The duplicate
        // "Master Brand" and the separate partner labels are gone.
        $this->assertSame(2, array_count_values($labels)[__('lba.nav.principal.brands')] ?? 0);
        $this->assertNotContains(__('principal.brands.title'), $labels);
        $this->assertNotContains(__('principal.tenants.title'), $labels);

        // "Pengaturan Global" closes the governance group, and it sits BELOW
        // "Brand & Mitra", matching the documented order in
        // docs/PANELS_NAVIGATION.md.
        $this->assertSame(2, array_count_values($labels)[__('principal.settings.title')] ?? 0);
        $this->assertLessThan(
            array_search(__('principal.settings.title'), $labels, true),
            array_search(__('lba.nav.principal.brands'), $labels, true),
        );

        // Account self-service is no longer a sidebar item...
        $this->assertNotContains(__('lba.account.nav'), $labels);
        $this->assertNotContains(__('lba.password_change.nav'), $labels);

        // ...it lives in the top-right account menu, labelled with the acting
        // identity's name and initials fallback.
        $this->assertStringContainsString('data-principal-account', $html);
        $this->assertStringContainsString('RW', $html);
        $this->assertStringContainsString(route('filament.principal.pages.account-profile'), $html);
        $this->assertStringContainsString(route('filament.principal.pages.change-password'), $html);
        $this->assertStringContainsString(route('filament.principal.auth.logout'), $html);
    }

    public function test_clinic_tailadmin_shell_uses_the_same_top_right_account_menu(): void
    {
        $actor = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($actor, Tenant::factory()->create(), [RoleKey::TENANT_OWNER]);

        $html = $this->actingAs($actor)->get(route('clinic.tailadmin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-principal-account', $html);
        $this->assertStringContainsString(route('filament.clinic.pages.account-profile'), $html);
        $this->assertStringContainsString(route('filament.clinic.pages.change-password'), $html);
        $this->assertStringContainsString(route('filament.clinic.auth.logout'), $html);

        $labels = $this->principalLinkLabels($html);
        $this->assertNotContains(__('lba.account.nav'), $labels);
        $this->assertNotContains(__('lba.password_change.nav'), $labels);
    }

    public function test_business_analytics_and_system_audit_render_in_the_shared_shell_and_stay_permission_gated(): void
    {
        $actor = $this->principal();

        // Both read-only governance surfaces render through the ONE shared
        // TailAdmin shell — same sidebar and top-right account menu as every
        // other governance screen — not a separate panel shell.
        $analytics = $this->actingAs($actor)->get(route('principal.tailadmin.analytics.index'))->assertOk();
        $analytics->assertSee('data-principal-account');
        $analytics->assertSee(__('lba.analytics.title'));

        $audit = $this->actingAs($actor)->get(route('principal.tailadmin.audit.index'))->assertOk();
        $audit->assertSee('data-principal-account');
        $audit->assertSee(__('lba.nav.principal.audit'));

        // The active menu entry is marked for the page actually being viewed.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('principal.tailadmin.analytics.index'), '/').'"\s+aria-current="page"/',
            $analytics->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('principal.tailadmin.audit.index'), '/').'"\s+aria-current="page"/',
            $audit->getContent(),
        );

        // A finance principal holds platform.analytics.view but NOT
        // platform.audit.view: the analytics surface opens and its menu entry is
        // present, while the audit surface is refused by both the menu and the
        // direct URL.
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);
        $financeAnalytics = $this->actingAs($finance)->get(route('principal.tailadmin.analytics.index'))->assertOk();
        $this->assertStringContainsString(route('principal.tailadmin.analytics.index'), $financeAnalytics->getContent());
        $this->assertStringNotContainsString(route('principal.tailadmin.audit.index'), $financeAnalytics->getContent());

        $this->actingAs($finance)->get(route('principal.tailadmin.audit.index'))->assertForbidden();
    }

    public function test_the_read_only_support_banner_renders_once_across_the_shared_shell(): void
    {
        $actor = $this->principal();
        $tenant = Tenant::factory()->create(['name' => 'Klinik Dukungan']);

        app(SupportTenantSession::class)->open($actor, $tenant->getKey());

        // The Filament dashboard and the TailAdmin governance screens render
        // through the SAME shell, and the bounded, read-only banner appears
        // exactly ONCE on each. (The dashboard renders the banner through the
        // panel's PAGE_START render hook, so the shell must not add a second
        // copy; the TailAdmin controller pages never hit that hook and rely on
        // the shell.)
        foreach ([route('filament.principal.pages.dashboard'), route('principal.tailadmin.brands.index')] as $url) {
            $html = $this->actingAs($actor)->get($url)->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, 'wire:key="lba-support-banner"'),
                "the read-only support banner must render exactly once on {$url}",
            );
            $this->assertStringContainsString('data-principal-account', $html);
            $this->assertStringContainsString($tenant->name, $html);
        }
    }

    /**
     * @return list<string>
     */
    private function principalLinkLabels(string $html): array
    {
        preg_match_all('/<a class="principal-link"[^>]*>(.*?)<\/a>/s', $html, $matches);

        return array_map(
            static fn (string $text): string => trim(html_entity_decode(strip_tags($text), ENT_QUOTES)),
            $matches[1],
        );
    }

    public function test_the_master_brand_uses_central_data_and_preserves_report_identity(): void
    {
        $this->actingAs($this->principal());
        $this->get('/principal/master-brands/create')->assertOk();

        $this->post('/principal/master-brands', [
            'name' => 'NuBiom',
            'slug' => 'nubiom',
            'status' => Brand::STATUS_ACTIVE,
            'report_display_name' => 'Ignored',
        ])->assertRedirect('/principal/master-brands');

        $brand = Brand::query()->where('slug', 'nubiom')->sole();
        $this->assertNull($brand->report_display_name);
        $brand->update(['report_display_name' => 'Original report name']);

        $this->get("/principal/master-brands/{$brand->id}/edit")->assertOk()->assertSee('NuBiom');

        $this->patch("/principal/master-brands/{$brand->id}", [
            'name' => 'NuBiom Malaysia',
            'slug' => 'nubiom',
        ])->assertRedirect('/principal/master-brands');

        $this->patch("/principal/master-brands/{$brand->id}/status", [
            'status' => Brand::STATUS_SUSPENDED,
        ])->assertRedirect('/principal/master-brands');

        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'name' => 'NuBiom Malaysia',
            'status' => Brand::STATUS_SUSPENDED,
            'report_display_name' => 'Original report name',
        ]);

        Brand::factory()->create(['name' => 'NuBiom other', 'status' => Brand::STATUS_ACTIVE]);
        $this->get('/principal/master-brands?search=nubiom&status=suspended')
            ->assertOk()
            ->assertSee('NuBiom Malaysia')
            ->assertDontSee('NuBiom other');
    }

    public function test_master_brand_rejects_duplicate_slugs_and_protects_direct_urls(): void
    {
        $brand = Brand::factory()->create();
        $this->get('/principal/master-brands')->assertRedirect();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE));
        $this->get('/principal/master-brands')->assertForbidden();
        $this->patch("/principal/master-brands/{$brand->id}/status", ['status' => Brand::STATUS_SUSPENDED])
            ->assertForbidden();

        $this->actingAs(User::factory()->role(RoleKey::TENANT_OWNER)->create());
        $this->get('/principal/master-brands')->assertForbidden();

        $this->actingAs($this->principal());
        $this->postJson('/principal/master-brands', [
            'name' => 'Duplicate',
            'slug' => $brand->slug,
            'status' => Brand::STATUS_ACTIVE,
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_report_identity_preserves_brand_keys_and_private_logo(): void
    {
        $brand = Brand::factory()->create([
            'report_address' => 'Old address',
            'report_logo_disk' => 'local',
            'report_logo_path' => 'logos/original.png',
        ]);
        $path = "/principal/master-brands/{$brand->id}/report-identity";

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->get($path)->assertForbidden();
        $this->patch($path, [])->assertForbidden();

        $this->actingAs($this->principal())->get($path)->assertOk()->assertSee('Old address');
        $payload = [
            'report_display_name' => 'NuBiom Malaysia',
            'report_address' => null,
            'report_contact' => '+60 1234',
            'report_header' => 'Header',
            'report_footer' => 'Footer',
            'report_website' => 'https://nubiom.my',
            'report_language' => 'ms-MY',
            'report_logo_path' => 'public/replacement.png',
            'slug' => 'changed',
        ];

        $this->patchJson($path, array_replace($payload, [
            'report_website' => 'ftp://example.test',
            'report_language' => 'unsupported',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['report_website', 'report_language']);

        $this->patch($path, $payload)->assertRedirect($path);
        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'slug' => $brand->slug,
            'status' => $brand->status,
            'report_display_name' => 'NuBiom Malaysia',
            'report_address' => null,
            'report_language' => 'ms-MY',
            'report_logo_path' => 'logos/original.png',
        ]);
    }

    public function test_brand_tenant_relationships_are_scoped_and_support_reuses_the_existing_session(): void
    {
        $brand = Brand::factory()->create();
        $previous = Brand::factory()->create();
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_ACTIVE]);
        $otherTenant = Tenant::factory()->create();
        $tenant->brands()->attach($previous, ['is_primary' => true]);
        $path = "/principal/master-brands/{$brand->id}/tenants";

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))->get($path)->assertForbidden();
        $this->post($path, ['tenant_id' => $tenant->id])->assertForbidden();

        $this->actingAs($this->principal())->get($path)->assertOk();
        $this->post("{$path}/{$otherTenant->id}/open")->assertNotFound();
        $this->patch("{$path}/{$otherTenant->id}/primary")->assertNotFound();
        $this->post($path, ['tenant_id' => $tenant->id, 'is_primary' => '1'])->assertRedirect($path);
        $this->post($path, ['tenant_id' => $tenant->id])->assertRedirect($path);

        $this->assertDatabaseHas('tenant_brands', ['tenant_id' => $tenant->id, 'brand_id' => $brand->id, 'is_primary' => true]);
        $this->assertDatabaseHas('tenant_brands', ['tenant_id' => $tenant->id, 'brand_id' => $previous->id, 'is_primary' => false]);
        $this->get($path)->assertOk()->assertSee($tenant->name);
        $this->post("{$path}/{$tenant->id}/open")->assertRedirect('/principal/tenant-support')
            ->assertSessionHas(SupportTenantSession::SESSION_KEY.'.tenant_id', $tenant->id);

        $this->patch("/principal/master-brands/{$previous->id}/tenants/{$tenant->id}/primary")->assertRedirect();
        $this->assertDatabaseHas('tenant_brands', ['tenant_id' => $tenant->id, 'brand_id' => $previous->id, 'is_primary' => true]);
        $this->delete("{$path}/{$tenant->id}")->assertRedirect($path);
        $this->assertDatabaseMissing('tenant_brands', ['tenant_id' => $tenant->id, 'brand_id' => $brand->id]);
    }

    public function test_principal_can_manage_the_central_tenant_catalog(): void
    {
        $path = '/principal/master-tenants';
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))->get($path)->assertForbidden();
        $this->actingAs($this->principal())->get($path.'/create')->assertOk();
        $data = ['name' => 'TailAdmin Partner', 'slug' => 'tailadmin-partner', 'status' => Tenant::STATUS_ACTIVE];
        $this->post($path, $data)->assertRedirect();
        $tenant = Tenant::query()->where('slug', $data['slug'])->sole();
        $this->get("$path/{$tenant->id}/edit")->assertOk();
        $this->get($path.'?search=tailadmin-partner')->assertOk()->assertSee($data['name']);
        $this->post("$path/{$tenant->id}/open")->assertRedirect('/principal/tenant-support');
        $this->patch("$path/{$tenant->id}", array_replace($data, ['status' => Tenant::STATUS_SUSPENDED]))->assertRedirect($path);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'status' => Tenant::STATUS_SUSPENDED]);
    }

    public function test_generator_requires_an_active_super_admin_assignment_on_every_route(): void
    {
        $path = '/principal/crud-generator';
        $this->get($path)->assertRedirect();

        $actors = [
            $this->principal(RoleKey::BRAND_MANAGER),
            User::factory()->role(RoleKey::PRINCIPAL_SUPER_ADMIN)->create(),
            User::factory()->role(RoleKey::PATIENT_CUSTOMER)->create(),
        ];
        $revoked = $this->principal();
        $revoked->principalRoleAssignments()->update(['status' => 'revoked', 'revoked_at' => now()]);
        $actors[] = $revoked;
        $inactive = $this->principal();
        $inactive->update(['is_active' => false]);
        $actors[] = $inactive;

        foreach ($actors as $actor) {
            $this->actingAs($actor)->get($path)->assertForbidden();
            $this->post("{$path}/preview", BrandCrudDefinition::defaults())->assertForbidden();
            $this->post("{$path}/export", BrandCrudDefinition::defaults())->assertForbidden();
        }
    }

    public function test_generator_exports_only_validated_options_and_previews_the_source(): void
    {
        $this->actingAs($this->principal());
        $path = '/principal/crud-generator';
        $definition = BrandCrudDefinition::defaults();
        $definition['columns'] = ['status', 'name'];
        $definition['actions'] = ['edit'];

        $this->get($path)->assertOk();
        $this->post("{$path}/preview", $definition)->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('class BrandCrud');
        $this->post("{$path}/export", $definition)
            ->assertOk()
            ->assertDownload('master-brand.json')
            ->assertJsonPath('columns', ['name', 'status'])
            ->assertJsonPath('actions', ['edit']);

        $this->postJson("{$path}/preview", array_replace($definition, ['columns' => ['metadata']]))
            ->assertUnprocessable()->assertJsonValidationErrors('definition.columns.0');
        $this->postJson("{$path}/export", array_replace($definition, ['actions' => ['delete']]))
            ->assertUnprocessable()->assertJsonValidationErrors('definition.actions.0');
    }

    public function test_artisan_generation_is_deterministic_and_preserves_existing_files(): void
    {
        $directory = storage_path('framework/testing/brand-crud-'.Str::random());
        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists($directory.'/repeat');
        $target = $directory.'/BrandCrud.php';
        $input = $directory.'/definition.json';

        try {
            File::put($input, json_encode(BrandCrudDefinition::defaults(), JSON_THROW_ON_ERROR));
            $this->artisan('lba:crud:generate', ['definition' => $input, '--output' => $directory])
                ->assertSuccessful();
            $this->assertSame(File::get(app_path('Platform/Crud/Generated/BrandCrud.php')), File::get($target));

            $this->artisan('lba:crud:generate', ['definition' => $input, '--output' => $directory.'/repeat'])
                ->assertSuccessful();
            $this->assertSame(File::get($target), File::get($directory.'/repeat/BrandCrud.php'));

            File::put($target, 'existing');
            $this->artisan('lba:crud:generate', ['definition' => $input, '--output' => $directory])
                ->assertFailed();
            $this->assertSame('existing', File::get($target));

            File::put($input, '{"model":"App\\\\Models\\\\User"}');
            $this->artisan('lba:crud:generate', ['definition' => $input, '--output' => $directory])
                ->assertFailed();
            $this->assertSame('existing', File::get($target));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
