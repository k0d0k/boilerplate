<?php

namespace App\Filament\Principal\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Principal\Resources\SubscriptionPlanResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * WS4 — create a subscription plan.
 */
class CreateSubscriptionPlan extends CreateRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
