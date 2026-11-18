<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\ModulePackage;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\ModuleRegistry\ModuleStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 14 — trusted module package distribution (server layer).
 *
 * Proves the acceptance criteria: an UNSIGNED (or unverifiable) package is
 * rejected, a verified package is quarantined WITHOUT its code ever executing,
 * activation is per-tenant through the shared administration write path, and an
 * activation can be rolled back without deleting any data.
 */
class PrincipalTrustedModuleUploadTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, RefreshDatabase;

    private const MODULE = 'demo.showcase';

    private string $quarantine;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->quarantine = storage_path('app/test-module-quarantine-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->quarantine);

        config([
            'lba.module_packages.quarantine_path' => $this->quarantine,
            'lba.module_packages.publishers' => [],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->quarantine);

        foreach ($this->tempFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------- Access control

    public function test_the_module_manager_page_boots_for_an_authorized_principal(): void
    {
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/module-registry')
            ->assertOk()
            ->assertSee(__('lba.module_packages.title'));
    }

    public function test_a_principal_without_the_module_permission_is_denied(): void
    {
        // principal_finance holds subscription/billing/analytics authority only.
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->get('/principal/module-registry')
            ->assertForbidden();

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_FINANCE))
            ->post('/principal/module-registry/packages', ['package' => $this->zip(['module.json' => $this->manifest(self::MODULE)]), 'signature' => 'x'])
            ->assertForbidden();
    }

    public function test_the_sidebar_exposes_the_module_manager_entry(): void
    {
        $html = $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN))
            ->get('/principal/module-registry')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('principal.tailadmin.modules.index'), $html);
    }

    // ----------------------------------------------------- Upload / reject

    public function test_an_unsigned_package_is_rejected_and_never_quarantined(): void
    {
        $actor = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($actor);

        $this->from('/principal/module-registry')
            ->post('/principal/module-registry/packages', [
                'package' => $this->zip(['module.json' => $this->manifest($this::MODULE)]),
            ])
            ->assertRedirect('/principal/module-registry')
            ->assertSessionHasErrors('package');

        $package = ModulePackage::query()->firstOrFail();

        $this->assertTrue($package->isRejected());
        $this->assertSame('unsigned', $package->reason);
        $this->assertSame([], File::files($this->quarantine));

        $this->assertDatabaseHas('module_audit_logs', [
            'action' => 'package_upload',
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => 'unsigned',
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function test_a_signature_from_an_untrusted_publisher_fails_closed(): void
    {
        // A signature IS present, but no publisher key is approved, so nothing
        // may ever be trusted (fail closed by default).
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        [, $private] = $this->keypair();
        $bytes = file_get_contents($this->zip(['module.json' => $this->manifest($this::MODULE)]));

        $this->post('/principal/module-registry/packages', [
            'package' => $this->uploaded($bytes),
            'signature' => $this->sign($bytes, $private),
        ]);

        $package = ModulePackage::query()->firstOrFail();

        $this->assertTrue($package->isRejected());
        $this->assertSame('invalid_signature', $package->reason);
        $this->assertSame([], File::files($this->quarantine));
    }

    public function test_a_tampered_archive_is_rejected(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        $bytes = (string) file_get_contents($this->zip(['module.json' => $this->manifest($this::MODULE)]));
        $signature = $this->sign($bytes, $private);

        // Tamper AFTER signing: the detached signature no longer matches.
        $tampered = $bytes."\x00";

        $this->post('/principal/module-registry/packages', [
            'package' => $this->uploaded($tampered),
            'signature' => $signature,
            'publisher' => 'viacom',
        ]);

        $this->assertSame('invalid_signature', ModulePackage::query()->firstOrFail()->reason);
    }

    public function test_a_package_without_a_valid_manifest_is_rejected(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        // No `lba` block ⇒ not an LBA module.
        $bytes = (string) file_get_contents($this->zip(['module.json' => json_encode(['name' => 'PlainPackage'])]));

        $this->post('/principal/module-registry/packages', [
            'package' => $this->uploaded($bytes),
            'signature' => $this->sign($bytes, $private),
        ]);

        $this->assertSame('invalid_manifest', ModulePackage::query()->firstOrFail()->reason);
    }

    // --------------------------------------------- Trusted, no execution

    public function test_a_signed_package_is_quarantined_verbatim_without_executing_its_code(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $actor = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($actor);

        // A payload that WOULD create a marker file if its PHP ever ran.
        $marker = storage_path('app/executed-'.Str::lower(Str::random(8)).'.marker');
        $this->tempFiles[] = $marker;

        $bytes = (string) file_get_contents($this->zip([
            'module.json' => $this->manifest($this::MODULE),
            'payload.php' => '<?php file_put_contents('.var_export($marker, true).', "pwned");',
        ]));

        $this->post('/principal/module-registry/packages', [
            'package' => $this->uploaded($bytes),
            'signature' => $this->sign($bytes, $private),
            'publisher' => 'viacom',
        ])->assertSessionHasNoErrors();

        $package = ModulePackage::query()->firstOrFail();

        $this->assertTrue($package->isVerified());
        $this->assertSame($this::MODULE, $package->module_key);
        $this->assertSame('viacom', $package->publisher);

        // The uploaded PHP was NEVER executed.
        $this->assertFileDoesNotExist($marker);

        // The archive sits in the private quarantine, byte-for-byte identical.
        $stored = $this->quarantine.DIRECTORY_SEPARATOR.$package->archive_path;
        $this->assertFileExists($stored);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $stored));
        $this->assertSame($package->checksum, hash_file('sha256', $stored));

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => $this::MODULE,
            'action' => 'package_upload',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'actor_id' => $actor->getKey(),
        ]);
    }

    // ------------------------------------------------ Activation + rollback

    public function test_activation_is_per_tenant_through_the_shared_write_path(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $tenant = Tenant::factory()->create();
        $actor = $this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN);
        $this->actingAs($actor);

        $package = $this->uploadVerified($private, $this::MODULE);

        $this->post("/principal/module-registry/packages/{$package->getKey()}/activate", ['tenant' => $tenant->getKey()])
            ->assertSessionHasNoErrors();

        $installation = ModuleInstallation::query()
            ->where('module_key', $this::MODULE)
            ->where('scope', ModuleScope::TENANT)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        $this->assertSame(ModuleStatus::ENABLED, $installation->status);
        $this->assertTrue($package->fresh()->isActivated());
        $this->assertSame($tenant->getKey(), $package->fresh()->activated_tenant_id);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => $this::MODULE,
            'action' => 'package_activate',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    public function test_activation_of_an_unknown_module_is_denied_and_leaves_no_installation(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        // A well-formed, correctly signed manifest for a module that is NOT
        // declared on the platform: verified, but never activatable.
        $package = $this->uploadVerified($private, 'ghost.module');
        $this->assertTrue($package->isVerified());

        $this->post("/principal/module-registry/packages/{$package->getKey()}/activate", ['tenant' => $tenant->getKey()])
            ->assertSessionHasErrors('activate');

        $this->assertTrue($package->fresh()->isVerified());

        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'ghost.module',
            'scope_key' => $tenant->getKey(),
        ]);
    }

    public function test_rollback_disables_without_deleting_data(): void
    {
        [$public, $private] = $this->keypair();
        config(['lba.module_packages.publishers' => ['viacom' => $public]]);

        $tenant = Tenant::factory()->create();
        $this->actingAs($this->principal(RoleKey::PRINCIPAL_MODULE_ADMIN));

        $package = $this->uploadVerified($private, $this::MODULE);
        $this->post("/principal/module-registry/packages/{$package->getKey()}/activate", ['tenant' => $tenant->getKey()]);

        $this->post("/principal/module-registry/packages/{$package->getKey()}/rollback")->assertSessionHasNoErrors();

        $installation = ModuleInstallation::query()
            ->where('module_key', $this::MODULE)
            ->where('scope_key', $tenant->getKey())
            ->firstOrFail();

        // Non-destructive: the row is kept and only disabled.
        $this->assertSame(ModuleStatus::DISABLED, $installation->status);
        $this->assertSame(ModulePackage::STATE_ROLLED_BACK, $package->fresh()->state);

        $this->assertDatabaseHas('module_audit_logs', [
            'module_key' => $this::MODULE,
            'action' => 'package_rollback',
            'result' => ModuleAuditLog::RESULT_ALLOWED,
            'scope_key' => $tenant->getKey(),
        ]);
    }

    // ------------------------------------------------------------- Helpers

    private function uploadVerified(\OpenSSLAsymmetricKey $private, string $moduleKey): ModulePackage
    {
        $bytes = (string) file_get_contents($this->zip(['module.json' => $this->manifest($moduleKey)]));

        $this->post('/principal/module-registry/packages', [
            'package' => $this->uploaded($bytes),
            'signature' => $this->sign($bytes, $private),
            'publisher' => 'viacom',
        ])->assertSessionHasNoErrors();

        return ModulePackage::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $this->tempFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', null, true);
    }

    private function uploaded(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $this->tempFiles[] = $path;

        File::put($path, $bytes);

        return new UploadedFile($path, 'package.zip', 'application/zip', null, true);
    }

    private function manifest(string $moduleKey): string
    {
        return (string) json_encode([
            'name' => 'UploadedPackage',
            'lba' => [
                'key' => $moduleKey,
                'version' => '1.2.3',
                'min_platform' => '3.0.0',
                'filament_panels' => ['clinic'],
                'requires' => [],
                'permissions' => [],
                'translation_locales' => ['id', 'ms-MY', 'en'],
                'scope' => ['tenant'],
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: \OpenSSLAsymmetricKey}
     */
    private function keypair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($resource, 'Unable to generate a test key pair.');

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        return [$details['key'], $resource];
    }

    private function sign(string $bytes, \OpenSSLAsymmetricKey $private): string
    {
        openssl_sign($bytes, $signature, $private, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
