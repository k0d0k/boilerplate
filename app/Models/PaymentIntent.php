<?php

namespace App\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Central payment intent registry entry (WS3).
 *
 * A gateway-agnostic, NON-CLINICAL index that maps a gateway reference back to
 * the tenant + order that initiated a charge. It exists so a secure webhook —
 * which arrives with no tenant context — can resolve the correct tenant
 * database. Only ids, amounts and status are held here.
 */
#[Fillable([
    'tenant_id',
    'order_id',
    'order_number',
    'provider',
    'provider_reference',
    'currency',
    'amount',
    'status',
    'idempotency_key',
    'payload',
])]
class PaymentIntent extends CentralModel
{
    use HasUlids;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }
}
