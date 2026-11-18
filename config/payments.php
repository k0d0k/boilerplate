<?php

use Modules\Commerce\Payments\Drivers\IpaymuDriver;
use Modules\Commerce\Payments\Drivers\MidtransDriver;
use Modules\Commerce\Payments\Drivers\PaypalDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Commerce payment gateways (WS3)
    |--------------------------------------------------------------------------
    |
    | Payment configuration is CENTRAL (platform-wide), not per tenant: a tenant
    | orders and pays, but the gateway credentials belong to the platform and are
    | administered in Principal Admin. Each gateway row carries a `mode`
    | (sandbox|live) and an encrypted credentials payload.
    |
    | The `driver` maps a provider to its adapter; `credentials` / `config` list
    | the field names the Principal form collects. Secret fields are encrypted at
    | rest and are never logged or returned in a webhook payload.
    |
    */

    'gateways' => [
        MidtransDriver::PROVIDER => [
            'driver' => MidtransDriver::class,
            'credentials' => ['server_key', 'client_key'],
            'config' => [],
        ],
        IpaymuDriver::PROVIDER => [
            'driver' => IpaymuDriver::class,
            'credentials' => ['api_key', 'va'],
            'config' => [],
        ],
        PaypalDriver::PROVIDER => [
            'driver' => PaypalDriver::class,
            'credentials' => ['client_id', 'client_secret'],
            'config' => ['webhook_id'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Live payments (fail-closed master switch)
    |--------------------------------------------------------------------------
    |
    | Live payments are DISABLED by default. Even when a gateway row is set to
    | `live`, a charge is only attempted when this switch is on AND the row is
    | enabled, credentials are present and the row has been verified. Sandbox
    | charges remain available so integration can be exercised safely.
    |
    */

    'live_enabled' => (bool) env('PAYMENTS_LIVE_ENABLED', false),

    'default_mode' => (string) env('PAYMENTS_DEFAULT_MODE', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Webhook endpoint
    |--------------------------------------------------------------------------
    |
    | One gateway-agnostic, secure webhook endpoint per provider. The handler
    | verifies the gateway signature, resolves the originating tenant from the
    | central intent registry and settles the tenant order idempotently.
    |
    */

    'webhook' => [
        'prefix' => 'api/v1/payments',
        'verify_signature' => (bool) env('PAYMENTS_VERIFY_WEBHOOK_SIGNATURE', true),
    ],

];
