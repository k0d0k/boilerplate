<?php

namespace App\Platform\ModuleRegistry\Models;

use App\Models\User;
use Database\Factories\ModulePackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uploaded trusted module package (Phase 14).
 *
 * Records the provenance and review state of an archived module package that a
 * publisher signed. A package is only ever TRUSTED once its detached signature
 * verifies against an approved publisher key and its `lba` manifest parses; it
 * is activated for a tenant through the SAME {@see ModuleAdministration} write
 * path as any declared module, so nothing bypasses the FeatureGate.
 *
 * The stored archive is inert: it is never extracted into the application and
 * no PHP from it is ever executed.
 */
#[Fillable([
    'module_key',
    'version',
    'publisher',
    'state',
    'checksum',
    'signature',
    'archive_path',
    'original_name',
    'reason',
    'activated_tenant_id',
    'uploaded_by',
    'metadata',
])]
class ModulePackage extends Model
{
    /** @use HasFactory<ModulePackageFactory> */
    use HasFactory, HasUlids;

    /** Stored in the private quarantine, not yet trusted. */
    public const STATE_QUARANTINED = 'quarantined';

    /** Signature + manifest verified; trusted but not yet activated. */
    public const STATE_VERIFIED = 'verified';

    /** Rejected (unsigned, bad signature, bad manifest, …); never trusted. */
    public const STATE_REJECTED = 'rejected';

    /** Activated for one tenant through the module administration write path. */
    public const STATE_ACTIVATED = 'activated';

    /** Activation was rolled back; no data was deleted. */
    public const STATE_ROLLED_BACK = 'rolled_back';

    protected static function newFactory(): ModulePackageFactory
    {
        return ModulePackageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function isVerified(): bool
    {
        return $this->state === self::STATE_VERIFIED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    public function isActivated(): bool
    {
        return $this->state === self::STATE_ACTIVATED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
