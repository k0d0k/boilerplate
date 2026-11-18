<?php

namespace App\Filament\Principal\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Principal\Resources\SubscriptionPlanResource;
use Filament\Resources\Pages\EditRecord;

/**
 * WS4 — edit a subscription plan.
 *
 * There is no delete action: plans are archived, never destroyed.
 */
class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
