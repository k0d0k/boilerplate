<?php

namespace App\Filament\Principal\Resources\AiWalletResource\RelationManagers;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Platform\Access\PermissionKey;
use App\Platform\AiBilling\AiWalletService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * WS4 — the immutable AI usage history of one tenant wallet.
 *
 * One row per CAPTURED billable inference, written once by
 * {@see AiWalletService}. Non-identifying only: the
 * opaque job reference, provider/model and integer units — never an image, a
 * patient identity or a clinical finding.
 */
class AiUsageRelationManager extends RelationManager
{
    use AuthorizesPrincipalResources;

    protected static string $relationship = 'usageRecords';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.wallets.usage_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
            || static::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recorded_at')
                    ->label(__('lba.wallets.recorded_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('lba.wallets.provider'))
                    ->badge(),
                TextColumn::make('model_name')
                    ->label(__('lba.wallets.model_name'))
                    ->placeholder('—'),
                TextColumn::make('credit_units')
                    ->label(__('lba.wallets.credit_units'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_price_credits')
                    ->label(__('lba.wallets.unit_price'))
                    ->numeric(),
                TextColumn::make('job_reference')
                    ->label(__('lba.wallets.job_reference'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('recorded_at', 'desc')
            ->emptyStateHeading(__('lba.wallets.no_usage'));
    }

    protected function canCreate(): bool
    {
        return false;
    }
}
