<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\PaymentGatewayResource;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Platform\Access\PermissionKey;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Support\Currency;

/**
 * Phase 5 — Principal "Penyedia Pembayaran" (payment gateway configuration)
 * TailAdmin surface.
 *
 * Mirrors the interim Filament {@see PaymentGatewayResource}.
 * Payment configuration is CENTRAL and platform-wide: it is administered here,
 * never per tenant. Each provider has one row whose credentials are ENCRYPTED at
 * rest and never displayed again — an operator can only replace a secret, never
 * read it back. A blank secret keeps the stored value.
 *
 * Two safety rules are enforced (and mirrored by the PaymentManager): a gateway
 * cannot be verified until every required credential is present; and gateway
 * configuration is disabled, never destroyed. Every read and write re-derives
 * `platform.payment.manage` server side.
 */
final class PaymentGatewayController extends Controller
{
    use AuthorizesPrincipalResources;

    public function index(): View
    {
        $this->authorizeManage();

        return view('principal.payment-providers.index', [
            'gateways' => PaymentGateway::query()->orderBy('label')->get(),
            'configured' => PaymentGateway::query()
                ->orderBy('label')
                ->get()
                ->mapWithKeys(fn (PaymentGateway $gateway): array => [(string) $gateway->getKey() => $this->isConfigured($gateway)])
                ->all(),
        ]);
    }

    public function create(): View
    {
        $this->authorizeManage();

        return view('principal.payment-providers.form', [
            'gateway' => new PaymentGateway([
                'mode' => PaymentGateway::MODE_SANDBOX,
                'enabled' => false,
            ]),
            'providerOptions' => $this->providerOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        PaymentGateway::query()->create($this->validated($request, null));

        return to_route('principal.tailadmin.payment-providers.index')
            ->with('success', __('lba.payments.saved'));
    }

    public function edit(PaymentGateway $gateway): View
    {
        $this->authorizeManage();

        return view('principal.payment-providers.form', [
            'gateway' => $gateway,
            'providerOptions' => $this->providerOptions(),
        ]);
    }

    public function update(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $this->authorizeManage();

        $gateway->update($this->validated($request, $gateway));

        return to_route('principal.tailadmin.payment-providers.index')
            ->with('success', __('lba.payments.saved'));
    }

    public function verify(PaymentGateway $gateway): RedirectResponse
    {
        $this->authorizeManage();

        if (! $this->isConfigured($gateway)) {
            return to_route('principal.tailadmin.payment-providers.index')
                ->withErrors(['configured' => __('lba.payments.not_configured_notice')]);
        }

        $gateway->forceFill(['verified_at' => now()])->save();

        return to_route('principal.tailadmin.payment-providers.index')
            ->with('success', __('lba.payments.verified_notice'));
    }

    public function unverify(PaymentGateway $gateway): RedirectResponse
    {
        $this->authorizeManage();

        $gateway->forceFill(['verified_at' => null])->save();

        return to_route('principal.tailadmin.payment-providers.index')
            ->with('success', __('lba.payments.unverified_notice'));
    }

    /**
     * Assemble the encrypted credential payload and non-secret config from the
     * flat form fields. A blank secret keeps the stored value, so editing a
     * gateway never requires re-entering credentials. The provider key of an
     * existing gateway can never change.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PaymentGateway $gateway): array
    {
        $data = $request->validate([
            'provider' => [$gateway instanceof PaymentGateway ? 'nullable' : 'required', Rule::in(array_keys($this->providerOptions()))],
            'label' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(PaymentGateway::MODES)],
            'enabled' => ['nullable', 'boolean'],
            'currencies' => ['nullable', 'array'],
            'currencies.*' => ['string', Rule::in(Currency::SUPPORTED)],
            'config_webhook_id' => ['nullable', 'string', 'max:255'],
            'credential_server_key' => ['nullable', 'string', 'max:255'],
            'credential_client_key' => ['nullable', 'string', 'max:255'],
            'credential_api_key' => ['nullable', 'string', 'max:255'],
            'credential_va' => ['nullable', 'string', 'max:255'],
            'credential_client_id' => ['nullable', 'string', 'max:255'],
            'credential_client_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = $gateway instanceof PaymentGateway
            ? (string) $gateway->provider
            : (string) ($data['provider'] ?? '');

        $credentials = (array) ($gateway?->credentials ?? []);

        foreach ((array) config("payments.gateways.{$provider}.credentials", []) as $key) {
            $value = $request->input('credential_'.$key);

            if (is_string($value) && trim($value) !== '') {
                $credentials[$key] = trim($value);
            }
        }

        $config = (array) ($gateway?->config ?? []);
        $webhookId = $request->input('config_webhook_id');

        if (is_string($webhookId)) {
            $config['webhook_id'] = trim($webhookId) !== '' ? trim($webhookId) : null;
        }

        $currencies = array_values(array_filter((array) ($data['currencies'] ?? []), 'is_string'));

        return [
            'provider' => $provider,
            'label' => (string) $data['label'],
            'mode' => (string) $data['mode'],
            'enabled' => (bool) ($data['enabled'] ?? false),
            'credentials' => $credentials,
            'config' => $config,
            'currencies' => $currencies === [] ? null : $currencies,
        ];
    }

    /**
     * True when the provider row holds every credential its driver needs.
     */
    private function isConfigured(PaymentGateway $gateway): bool
    {
        $provider = (string) $gateway->provider;

        if (! is_string(config("payments.gateways.{$provider}.driver"))) {
            return false;
        }

        return app(PaymentManager::class)->driver($provider)->isConfigured($gateway);
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(): array
    {
        return app(PaymentManager::class)->providerOptions();
    }

    private function authorizeManage(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_PAYMENT_MANAGE), 403);
    }
}
