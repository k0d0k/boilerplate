<?php

namespace Database\Factories;

use App\Models\PaymentGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentGateway>
 */
class PaymentGatewayFactory extends Factory
{
    protected $model = PaymentGateway::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'midtrans',
            'label' => 'Midtrans',
            'mode' => PaymentGateway::MODE_SANDBOX,
            'enabled' => false,
            'config' => ['webhook_id' => null],
            'credentials' => [],
            'currencies' => null,
            'verified_at' => null,
            'metadata' => null,
        ];
    }

    public function provider(string $provider, ?string $label = null): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
            'label' => $label ?? ucfirst($provider),
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => ['mode' => PaymentGateway::MODE_LIVE]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function credentials(array $credentials): static
    {
        return $this->state(fn (): array => ['credentials' => $credentials]);
    }

    public function verified(): static
    {
        return $this->state(fn (): array => ['verified_at' => now()]);
    }
}
