<?php

namespace App\Models;

use App\Platform\Tenancy\Models\CentralModel;
use Database\Factories\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Central payment gateway configuration (WS3).
 *
 * A commercial configuration row — never clinical data — pinned to the CENTRAL
 * connection. Credentials are stored ENCRYPTED at rest (`encrypted:array`), so
 * a database leak never exposes a gateway secret. The row is administered in
 * Principal Admin; a provider's live credentials only ever take effect when the
 * row is enabled, in `live` mode and verified.
 */
#[Fillable([
    'provider',
    'label',
    'mode',
    'enabled',
    'config',
    'credentials',
    'currencies',
    'verified_at',
    'metadata',
])]
class PaymentGateway extends CentralModel
{
    /** @use HasFactory<PaymentGatewayFactory> */
    use HasFactory, HasUlids;

    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    /** @var list<string> */
    public const MODES = [self::MODE_SANDBOX, self::MODE_LIVE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'currencies' => 'array',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isLive(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function isSandbox(): bool
    {
        return $this->mode === self::MODE_SANDBOX;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * A non-secret configuration value.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    /**
     * A secret credential value (decrypted on access). Never log or return it.
     */
    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    /**
     * True when EVERY required credential has a non-empty value.
     *
     * @param  list<string>  $required
     */
    public function isConfigured(array $required): bool
    {
        foreach ($required as $key) {
            $value = $this->credential($key);

            if ($value === null || $value === '' || $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Currencies this gateway may process. An empty list means "unspecified";
     * a caller then falls back to the platform default set.
     */
    public function allowsCurrency(string $currency): bool
    {
        $currencies = $this->currencies ?? [];

        if ($currencies === []) {
            return true;
        }

        return in_array(strtoupper($currency), array_map('strtoupper', $currencies), true);
    }
}
