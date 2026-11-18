<?php

namespace Database\Factories;

use App\Platform\ModuleRegistry\Models\ModulePackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModulePackage>
 */
class ModulePackageFactory extends Factory
{
    protected $model = ModulePackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module_key' => 'demo.showcase',
            'version' => '1.0.0',
            'publisher' => 'viacom',
            'state' => ModulePackage::STATE_VERIFIED,
            'checksum' => hash('sha256', Str::random(16)),
            'signature' => base64_encode(Str::random(64)),
            'archive_path' => 'packages/'.Str::ulid().'.zip',
            'original_name' => 'demo-showcase.zip',
            'reason' => null,
            'activated_tenant_id' => null,
            'uploaded_by' => null,
            'metadata' => null,
        ];
    }

    public function forKey(string $key, string $version = '1.0.0'): static
    {
        return $this->state(fn (array $attributes): array => [
            'module_key' => $key,
            'version' => $version,
        ]);
    }

    public function state(string $state): static
    {
        return $this->state(fn (array $attributes): array => ['state' => $state]);
    }

    public function rejected(string $reason = 'unsigned'): static
    {
        return $this->state(fn (array $attributes): array => [
            'state' => ModulePackage::STATE_REJECTED,
            'reason' => $reason,
        ]);
    }
}
