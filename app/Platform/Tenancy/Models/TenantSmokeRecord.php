<?php

namespace App\Platform\Tenancy\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Minimal non-clinical smoke-test table that lives in each tenant database
 * (Phase 2A). It exists only to demonstrate read/write isolation between
 * tenants and that branches of one tenant share the tenant database. It holds
 * no patient or clinical data (PRV-01).
 */
#[Fillable(['branch_id', 'label', 'payload'])]
class TenantSmokeRecord extends TenantModel
{
    use HasUlids;

    protected $table = 'tenant_smoke_records';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
