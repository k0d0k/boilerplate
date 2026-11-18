<?php

namespace App\Http\Requests\Principal;

use App\Models\Brand;
use App\Models\Tenant;
use App\Platform\Settings\PlatformSettings;
use App\Platform\Tenancy\Manual\ManualTenantDatabaseConfigurator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partner create/update validation (v3.3).
 *
 * Besides the identity fields, the SAME form may (a) link a Brand — the existing
 * Brand–Partner relation, including which link is primary — and (b) record a
 * MANUAL database target. The database rules are deliberately shape-only here
 * (identifier length/charset via `max`); the semantic guarantees that must never
 * be bypassed — never the central database, never a name already claimed by
 * another partner, never re-pointing an already provisioned partner — live in
 * {@see ManualTenantDatabaseConfigurator} and are re-checked server side in
 * {@see withValidator()}. The password stays write-only: `db_password` is
 * flash-excluded in bootstrap/app.php, so it never lands in the session.
 */
final class SaveTenantRequest extends FormRequest
{
    public const STATUSES = [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED];

    public function rules(): array
    {
        $slug = Rule::unique(Tenant::class, 'slug');
        $tenant = $this->route('tenant');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $tenant instanceof Tenant ? $slug->ignore($tenant) : $slug],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],

            'brand_id' => ['nullable', 'ulid', Rule::exists(Brand::class, 'id')],
            'is_primary_brand' => ['nullable', 'boolean'],

            'database_mode' => ['nullable', 'string', Rule::in([PlatformSettings::MODE_MANUAL])],
            'db_name' => [
                'nullable', 'string', 'max:64',
                'required_if:database_mode,'.PlatformSettings::MODE_MANUAL,
            ],
            'db_username' => ['nullable', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Re-derive the MANUAL database safety rules server side. The identifier
     * shape is validated here; the registry checks are delegated to the single
     * write-path service so the form and the service can never disagree.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Never pile a semantic error on top of a shape error.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('database_mode') !== PlatformSettings::MODE_MANUAL) {
                return;
            }

            $tenant = $this->route('tenant');
            $reason = app(ManualTenantDatabaseConfigurator::class)->reason(
                (string) $this->input('db_name', ''),
                $tenant instanceof Tenant ? $tenant : null,
            );

            if ($reason !== null) {
                $validator->errors()->add('db_name', __('principal.tenants.database.config_error_'.$reason));
            }
        });
    }

    /**
     * Whether the operator chose to record a MANUAL database target.
     */
    public function usesManualDatabase(): bool
    {
        return $this->input('database_mode') === PlatformSettings::MODE_MANUAL;
    }
}
