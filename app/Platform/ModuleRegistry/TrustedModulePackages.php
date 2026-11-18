<?php

namespace App\Platform\ModuleRegistry;

use App\Models\User;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\Models\ModulePackage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Phase 14 — trusted module package distribution.
 *
 * The ONLY entry point that accepts an uploaded module package. It writes the
 * archive VERBATIM into a private quarantine and reads nothing but its
 * `module.json` manifest — package code is NEVER executed and the archive is
 * NEVER extracted or autoloaded. A package becomes TRUSTED only when:
 *
 *  1. a detached SHA-256 signature over the raw archive bytes verifies against
 *     an APPROVED publisher public key (no key configured ⇒ nothing is ever
 *     trusted), and
 *  2. the archive contains a valid `lba` manifest declaration.
 *
 * Per-tenant activation reuses the SAME {@see ModuleAdministration} write path
 * the Module Manager uses, so the FeatureGate, dependency checks, scope rules
 * and audit trail are identical — an uploaded package grants nothing on its
 * own. Activation can be rolled back non-destructively (disable + revoke); no
 * clinical, financial or patient record is ever deleted.
 */
final class TrustedModulePackages
{
    private const ACTION_UPLOAD = 'package_upload';

    private const ACTION_ACTIVATE = 'package_activate';

    private const ACTION_ROLLBACK = 'package_rollback';

    /** Upper bound on the manifest we will read from an archive (bytes). */
    private const MAX_MANIFEST_BYTES = 1_048_576;

    public function __construct(
        private readonly ModuleDiscovery $discovery,
        private readonly ModuleAdministration $administration,
        private readonly RegistersModules $registry,
    ) {}

    /**
     * Accept an uploaded package for review.
     *
     * Never throws on an untrusted package: the rejection is recorded as a
     * {@see ModulePackage} row (state `rejected`) and audited. The returned row
     * is `verified` only when both the signature and the manifest are sound.
     */
    public function upload(
        string $archivePath,
        ?string $originalName,
        string $signatureBase64,
        ?string $publisher = null,
        ?User $actor = null,
    ): ModulePackage {
        $bytes = @file_get_contents($archivePath);
        $checksum = is_string($bytes) && $bytes !== '' ? hash('sha256', $bytes) : hash('sha256', '');

        if (! is_string($bytes) || $bytes === '') {
            return $this->reject(null, null, $checksum, $signatureBase64, $publisher, 'empty_archive', $actor, $originalName);
        }

        $publisherId = $this->verifySignature($bytes, $signatureBase64, $publisher);

        if ($publisherId === null) {
            $reason = trim($signatureBase64) === '' ? 'unsigned' : 'invalid_signature';

            return $this->reject(null, null, $checksum, $signatureBase64, $publisher, $reason, $actor, $originalName);
        }

        // Read the manifest as JSON ONLY. No package code is loaded or run.
        $manifest = $this->readManifest($archivePath);

        if ($manifest === null) {
            return $this->reject(null, null, $checksum, $signatureBase64, $publisherId, 'invalid_manifest', $actor, $originalName);
        }

        $archivePathName = $this->quarantine($bytes, $manifest->key);

        $package = ModulePackage::query()->create([
            'module_key' => $manifest->key,
            'version' => $manifest->version,
            'publisher' => $publisherId,
            'state' => ModulePackage::STATE_VERIFIED,
            'checksum' => $checksum,
            'signature' => $signatureBase64,
            'archive_path' => $archivePathName,
            'original_name' => $originalName,
            'reason' => null,
            'activated_tenant_id' => null,
            'uploaded_by' => $actor?->getKey(),
            'metadata' => [
                'min_platform_version' => $manifest->minPlatformVersion,
                'required_dependencies' => $manifest->requiredDependencies,
                'scope_compatibility' => $manifest->scopeCompatibility,
            ],
        ]);

        $this->audit($manifest->key, self::ACTION_UPLOAD, ModuleAuditLog::RESULT_ALLOWED, null, $actor);

        return $package;
    }

    /**
     * Activate a verified package for ONE tenant via the shared administration
     * write path (install + entitlement, dependency-checked, audited).
     */
    public function activate(string $packageId, string $tenantId, ?User $actor = null): bool
    {
        $package = ModulePackage::query()->find($packageId);

        if ($package === null) {
            return false;
        }

        if (! $package->isVerified() || $package->module_key === null) {
            $this->audit($package->module_key, self::ACTION_ACTIVATE, ModuleAuditLog::RESULT_DENIED, 'package_not_verified', $actor, $tenantId);

            return false;
        }

        $enabled = $this->administration->enable($package->module_key, $tenantId, $actor, self::ACTION_ACTIVATE);

        if (! $enabled) {
            return false;
        }

        $package->forceFill([
            'state' => ModulePackage::STATE_ACTIVATED,
            'activated_tenant_id' => $tenantId,
        ])->save();

        return true;
    }

    /**
     * Roll back an activation non-destructively: disable + revoke the module for
     * the previously activated tenant. No data is deleted.
     */
    public function rollback(string $packageId, ?User $actor = null): bool
    {
        $package = ModulePackage::query()->find($packageId);

        if ($package === null || $package->module_key === null || $package->activated_tenant_id === null) {
            return false;
        }

        $tenantId = (string) $package->activated_tenant_id;

        $this->administration->disable($package->module_key, $tenantId, $actor);

        $package->forceFill([
            'state' => ModulePackage::STATE_ROLLED_BACK,
            'activated_tenant_id' => null,
        ])->save();

        $this->audit($package->module_key, self::ACTION_ROLLBACK, ModuleAuditLog::RESULT_ALLOWED, null, $actor, $tenantId);

        return true;
    }

    /**
     * @return Collection<int, ModulePackage>
     */
    public function packages(): Collection
    {
        return ModulePackage::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    public function find(string $packageId): ?ModulePackage
    {
        return ModulePackage::query()->find($packageId);
    }

    /**
     * Verify the detached signature against the approved publisher keys.
     *
     * Returns the verifying publisher id, or null when nothing verifies. A named
     * publisher uses ONLY its key; an unnamed one may match any approved key.
     */
    private function verifySignature(string $bytes, string $signatureBase64, ?string $publisher): ?string
    {
        $signature = base64_decode(trim($signatureBase64), true);

        if ($signature === false || $signature === '') {
            return null;
        }

        $publishers = (array) config('lba.module_packages.publishers', []);

        if ($publisher !== null && $publisher !== '') {
            $key = $publishers[$publisher] ?? null;

            return is_string($key) && $this->signatureMatches($bytes, $signature, $key) ? $publisher : null;
        }

        foreach ($publishers as $id => $key) {
            if (is_string($key) && $this->signatureMatches($bytes, $signature, $key)) {
                return (string) $id;
            }
        }

        return null;
    }

    private function signatureMatches(string $bytes, string $signature, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return false;
        }

        return openssl_verify($bytes, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Read the `lba` manifest from the archive WITHOUT extracting or executing
     * any package file. Only the single bounded `module.json` entry is read.
     */
    private function readManifest(string $archivePath): ?ModuleManifest
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            return null;
        }

        try {
            $stat = $zip->statName('module.json');

            if ($stat === false || ($stat['size'] ?? 0) > self::MAX_MANIFEST_BYTES) {
                return null;
            }

            $json = $zip->getFromName('module.json');
        } finally {
            $zip->close();
        }

        if (! is_string($json)) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $this->discovery->manifestFromData($data) : null;
    }

    /**
     * Write the archive verbatim to the private quarantine. Never extracted.
     */
    private function quarantine(string $bytes, string $moduleKey): string
    {
        $directory = (string) config('lba.module_packages.quarantine_path', storage_path('app/module-quarantine'));

        File::ensureDirectoryExists($directory);

        $name = Str::slug($moduleKey).'-'.Str::ulid().'.zip';

        File::put($directory.DIRECTORY_SEPARATOR.$name, $bytes);

        return $name;
    }

    private function reject(
        ?string $moduleKey,
        ?string $version,
        string $checksum,
        string $signatureBase64,
        ?string $publisher,
        string $reason,
        ?User $actor,
        ?string $originalName,
    ): ModulePackage {
        $package = ModulePackage::query()->create([
            'module_key' => $moduleKey,
            'version' => $version,
            'publisher' => null,
            'state' => ModulePackage::STATE_REJECTED,
            'checksum' => $checksum,
            'signature' => $signatureBase64 !== '' ? $signatureBase64 : null,
            'archive_path' => null,
            'original_name' => $originalName,
            'reason' => $reason,
            'activated_tenant_id' => null,
            'uploaded_by' => $actor?->getKey(),
            'metadata' => null,
        ]);

        $this->audit($moduleKey, self::ACTION_UPLOAD, ModuleAuditLog::RESULT_DENIED, $reason, $actor, null, $publisher);

        return $package;
    }

    private function audit(
        ?string $moduleKey,
        string $action,
        string $result,
        ?string $reason,
        ?User $actor,
        ?string $tenantId = null,
        ?string $publisher = null,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => $moduleKey,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => $tenantId !== null ? ModuleScope::TENANT : ModuleScope::PLATFORM,
            'scope_key' => $tenantId ?? '',
            'actor_id' => $actor?->getKey(),
            'metadata' => $publisher !== null ? ['publisher' => $publisher] : null,
        ]);
    }
}
