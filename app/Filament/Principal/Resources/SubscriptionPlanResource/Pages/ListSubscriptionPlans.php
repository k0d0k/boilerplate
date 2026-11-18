<?php

namespace App\Filament\Principal\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Principal\Resources\SubscriptionPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * WS4 — list the central subscription plan catalogue.
 */
class ListSubscriptionPlans extends ListRecords
{
    protected static string $resource = SubscriptionPlanResource::class;

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(static fn (): bool => SubscriptionPlanResource::canCreate()),
        ];
    }
}
