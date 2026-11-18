<?php

namespace Modules\Commerce\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — the append-only payment history of a single order.
 */
class OrderTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.commerce.orders.transactions_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrderResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('lba.commerce.orders.provider'))
                    ->badge(),
                TextColumn::make('provider_reference')
                    ->label(__('lba.commerce.orders.reference'))
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(__('lba.commerce.orders.amount'))
                    ->formatStateUsing(fn (PaymentTransaction $record): string => Currency::format((float) $record->amount, (string) $record->currency)),
                TextColumn::make('status')
                    ->label(__('lba.commerce.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.payment_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        PaymentTransaction::STATUS_SUCCEEDED => 'success',
                        PaymentTransaction::STATUS_FAILED, PaymentTransaction::STATUS_EXPIRED => 'danger',
                        PaymentTransaction::STATUS_REFUNDED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label(__('lba.commerce.orders.recorded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
