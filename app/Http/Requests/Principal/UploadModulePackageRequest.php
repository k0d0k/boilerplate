<?php

namespace App\Http\Requests\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\TrustedModulePackages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 14 — validate an uploaded trusted module package.
 *
 * The archive is bounded by size and type only; the real trust decision (signature
 * + manifest) is made server-side by {@see TrustedModulePackages}
 * and never by client input.
 */
final class UploadModulePackageRequest extends FormRequest
{
    use AuthorizesPrincipalResources;

    public function authorize(): bool
    {
        return self::principalCan(PermissionKey::PLATFORM_MODULE_MANAGE);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) config('lba.module_packages.max_kilobytes', 51200);

        return [
            'package' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:zip'],
            'signature' => ['nullable', 'string', 'max:8192'],
            'publisher' => ['nullable', 'string', 'max:64'],
        ];
    }
}
