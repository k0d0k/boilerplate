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
 * WS4 — the immutable ledger history of one tenant AI wallet.
 *
 * Read-only: entries are written once by {@see AiWalletService}
 * and never modified here. Every row is non-identifying accounting data only.
 */
class AiLedgerRelationManager extends RelationManager
{
    use AuthorizesPrincipalResources;

    protected static string $relationship = 'ledgerEntries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.wallets.ledger_title');
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
                TextColumn::make('created_at')
                    ->label(__('lba.wallets.recorded_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->label(__('lba.wallets.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.wallets.action_{$state}")),
                TextColumn::make('credit_units')
                    ->label(__('lba.wallets.credit_units'))
                    ->numeric(),
                TextColumn::make('available_after')
                    ->label(__('lba.wallets.available_after'))
                    ->numeric(),
                TextColumn::make('reserved_after')
                    ->label(__('lba.wallets.reserved_after'))
                    ->numeric(),
                TextColumn::make('job_reference')
                    ->label(__('lba.wallets.job_reference'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('actor_reference')
                    ->label(__('lba.wallets.actor'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('lba.wallets.note'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('lba.wallets.no_ledger'));
    }

    protected function canCreate(): bool
    {
        return false;
    }
}
