<?php

namespace App\Http\Controllers\Api\Patient\Concerns;

use App\Platform\Access\TenantOption;
use Modules\Appointments\Models\Appointment;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\OrderItem;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Support\Currency;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Models\SinaWallet;
use Modules\Consultations\Services\ConsultationTariffService;
use Modules\LBA\Models\ReportVersion;
use Modules\Patients\Models\Patient;
use Modules\Practitioners\Services\PractitionerAssignment;

/**
 * Phase 8B — the single place the API shapes each resource into JSON.
 *
 * Keeping the projections here means the panel and the API expose exactly the
 * same fields for the same record, and a clinical-of-record field can never be
 * accidentally added to a patient-facing payload in one place only.
 */
trait PresentsPatientApi
{
    /**
     * @return array{id: string, name: string, brand: string|null}
     */
    protected function clinicArray(TenantOption $option): array
    {
        return [
            'id' => $option->id,
            'name' => $option->name,
            'brand' => $option->brandLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function patientArray(Patient $patient): array
    {
        return [
            'patient_number' => $patient->patient_number,
            'full_name' => $patient->full_name,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'sex' => $patient->sex,
            'gender' => $patient->gender,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'address' => $patient->address,
            'city' => $patient->city,
            'region' => $patient->region,
            'country' => $patient->country,
            'registered_branch_id' => $patient->registered_branch_id,
            'status' => $patient->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function appointmentArray(Appointment $appointment): array
    {
        return [
            'id' => $appointment->getKey(),
            'status' => $appointment->status,
            'service_type' => $appointment->service_type,
            'starts_at' => $appointment->starts_at?->toIso8601String(),
            'ends_at' => $appointment->ends_at?->toIso8601String(),
            'branch' => $appointment->branch === null ? null : [
                'id' => $appointment->branch->getKey(),
                'name' => $appointment->branch->name,
            ],
            'notes' => $appointment->notes,
            'can_cancel' => $appointment->canTransitionTo(Appointment::STATUS_CANCELLED),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportArray(ReportVersion $version): array
    {
        return [
            'id' => $version->getKey(),
            'version' => $version->version,
            'branch' => $version->assessment?->examination?->branch?->name,
            'released_at' => $version->released_at?->toIso8601String(),
            'generated_at' => $version->generated_at?->toIso8601String(),
            'filename' => $version->filename(),
            'download_url' => url('/api/v1/patient/reports/'.$version->getKey().'/download'),
        ];
    }

    /**
     * A sellable product with its price EXPLICITLY resolved for one currency.
     *
     * @return array<string, mixed>
     */
    protected function productArray(Product $product, string $currency): array
    {
        $price = $product->priceFor($currency);

        return [
            'id' => $product->getKey(),
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => $product->unit,
            'kind' => $product->kind,
            'currency' => $currency,
            'price' => $price === null ? null : Currency::round($price, $currency),
            'available' => $price !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderItemArray(OrderItem $item): array
    {
        return [
            'product_id' => $item->product_id,
            'name' => $item->name,
            'sku' => $item->sku,
            'unit_price' => Currency::round((float) $item->unit_price, (string) $item->currency),
            'quantity' => (int) $item->quantity,
            'line_total' => Currency::round((float) $item->line_total, (string) $item->currency),
        ];
    }

    /**
     * A summary and (when requested) detail projection of a patient's own order.
     *
     * @return array<string, mixed>
     */
    protected function orderArray(Order $order, bool $withDetail = false): array
    {
        $currency = (string) $order->currency;

        $payload = [
            'id' => $order->getKey(),
            'order_number' => $order->order_number,
            'source' => $order->source,
            'status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status,
            'currency' => $currency,
            'subtotal' => Currency::round((float) $order->subtotal, $currency),
            'discount_total' => Currency::round((float) $order->discount_total, $currency),
            'tax_total' => Currency::round((float) $order->tax_total, $currency),
            'grand_total' => Currency::round((float) $order->grand_total, $currency),
            'paid_total' => Currency::round((float) $order->paid_total, $currency),
            'outstanding' => Currency::round($order->outstanding(), $currency),
            'placed_at' => $order->placed_at?->toIso8601String(),
            'branch' => $order->branch === null ? null : [
                'id' => $order->branch->getKey(),
                'name' => $order->branch->name,
            ],
        ];

        if (! $withDetail) {
            return $payload;
        }

        $payload['items'] = $order->items
            ->map(fn (OrderItem $item) => $this->orderItemArray($item))
            ->all();

        $payload['invoices'] = $order->invoices->map(fn ($invoice) => [
            'number' => $invoice->number,
            'amount' => Currency::round((float) $invoice->amount, (string) $invoice->currency),
            'status' => $invoice->status,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ])->all();

        $payload['transactions'] = $order->transactions->map(fn ($transaction) => [
            'provider' => $transaction->provider,
            'reference' => $transaction->provider_reference,
            'amount' => Currency::round((float) $transaction->amount, (string) $transaction->currency),
            'status' => $transaction->status,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ])->all();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function consultationArray(Consultation $consultation): array
    {
        return [
            'id' => $consultation->getKey(),
            'status' => $consultation->status,
            'channel' => $consultation->channel,
            'type' => $consultation->type,
            'scheduled_at' => $consultation->scheduled_at?->toIso8601String(),
            'started_at' => $consultation->started_at?->toIso8601String(),
            'ended_at' => $consultation->ended_at?->toIso8601String(),
            'reason' => $consultation->reason,
            'summary' => $consultation->summary,
            'branch' => $consultation->branch === null ? null : [
                'id' => $consultation->branch->getKey(),
                'name' => $consultation->branch->name,
            ],
            'practitioner' => $this->consultationPractitionerArray($consultation),
            'appointment' => $consultation->appointment === null ? null : [
                'id' => $consultation->appointment->getKey(),
                'starts_at' => $consultation->appointment->starts_at?->toIso8601String(),
                'ends_at' => $consultation->appointment->ends_at?->toIso8601String(),
            ],
            'can_chat' => $consultation->channel === Consultation::CHANNEL_SINA_CHAT && $consultation->isActive(),
            'tariff' => $this->consultationTariffArray($consultation),
            'refund' => $this->consultationRefundArray(),
        ];
    }

    /**
     * The named practitioner of a human consultation, when one is assigned.
     *
     * Only NON-clinical scheduling data is exposed (id + display label); the
     * assigned `staff_user_id` is resolved server-side through the registry and
     * fails closed (null) for a staff member who is not on the roster, or when
     * there is no verified tenant context — a forged identity can never leak.
     *
     * @return array{id: string, name: string}|null
     */
    protected function consultationPractitionerArray(Consultation $consultation): ?array
    {
        if (blank($consultation->staff_user_id)) {
            return null;
        }

        $practitioner = app(PractitionerAssignment::class)
            ->findByUserId((string) $consultation->staff_user_id);

        if ($practitioner === null) {
            return null;
        }

        return [
            'id' => (string) $practitioner->getKey(),
            'name' => trim(implode(' ', array_filter([
                $practitioner->title,
                $practitioner->display_name,
            ]))),
        ];
    }

    /**
     * The patient's informed tariff for a consultation, or null when the clinic
     * has not priced it. Never a fabricated amount.
     *
     * @return array{amount: string, currency: string}|null
     */
    protected function consultationTariffArray(Consultation $consultation): ?array
    {
        $tariff = app(ConsultationTariffService::class)->forConsultation($consultation);

        if ($tariff === null) {
            return null;
        }

        return [
            'amount' => Currency::round($tariff->amount, $tariff->currency),
            'currency' => $tariff->currency,
        ];
    }

    /**
     * The disclosed cancellation/refund terms.
     *
     * @return array{cancellable_before_start: bool, refundable: bool}
     */
    protected function consultationRefundArray(): array
    {
        $service = app(ConsultationTariffService::class);

        return [
            'cancellable_before_start' => $service->cancellableBeforeStart(),
            'refundable' => $service->refundable(),
        ];
    }

    /**
     * The transparent SINA fee unit, disclosed BEFORE any turn is charged.
     *
     * @return array{billing_enabled: bool, credits_per_turn: int, credits_per_1k_tokens: int, currency: string}
     */
    protected function sinaFeeArray(): array
    {
        $service = app(ConsultationTariffService::class);

        return [
            'billing_enabled' => $service->sinaBillingEnabled(),
            'credits_per_turn' => $service->sinaCreditsPerTurn(),
            'credits_per_1k_tokens' => $service->sinaCreditsPerThousandTokens(),
            'currency' => $service->sinaCreditCurrency(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function consultationMessageArray(ConsultationMessage $message): array
    {
        return [
            'id' => $message->getKey(),
            'role' => $message->role,
            'content' => $message->content,
            'provider' => $message->provider,
            'model' => $message->model,
            'created_at' => $message->created_at?->toIso8601String(),
            'grounded' => (bool) data_get($message->metadata, 'grounding.grounded', false),
            'citations' => $this->messageCitations($message),
        ];
    }

    /**
     * The VALIDATED source citations attached to a knowledge-grounded SINA reply.
     *
     * Only ever the citations the retrieval engine proved (title, real PDF page,
     * document date, private authenticated route) — never a fabricated one. An
     * ungrounded turn yields an empty list.
     *
     * @return list<array{document_id: string, version: int, page: int, title: string, published_on: ?string, url: string}>
     */
    protected function messageCitations(ConsultationMessage $message): array
    {
        $sources = data_get($message->metadata, 'grounding.sources', []);

        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_filter($sources, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sinaWalletArray(?SinaWallet $wallet): array
    {
        return [
            'balance_credits' => (int) ($wallet->balance_credits ?? 0),
            'reserved_credits' => (int) ($wallet->reserved_credits ?? 0),
            'lifetime_consumed_credits' => (int) ($wallet->lifetime_consumed_credits ?? 0),
            'currency' => $wallet?->currency ?? (string) config('lba.sina.billing.currency', 'sina_credit'),
            'status' => $wallet?->status ?? SinaWallet::STATUS_ACTIVE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sinaUsageArray(SinaUsageRecord $record): array
    {
        return [
            'id' => $record->getKey(),
            'consultation_id' => $record->consultation_id,
            'provider' => $record->provider,
            'model' => $record->model_name,
            'prompt_tokens' => (int) $record->prompt_tokens,
            'completion_tokens' => (int) $record->completion_tokens,
            'credit_units' => (int) $record->credit_units,
            'recorded_at' => $record->recorded_at?->toIso8601String(),
        ];
    }
}
