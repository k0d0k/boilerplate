<?php

namespace Modules\Commerce\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — the invoice(s) raised for a single order.
 */
class OrderInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.commerce.orders.invoices_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrderResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('lba.commerce.orders.invoice_number'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('amount')
                    ->label(__('lba.commerce.orders.invoice_amount'))
                    ->formatStateUsing(fn (Invoice $record): string => Currency::format((float) $record->amount, (string) $record->currency)),
                TextColumn::make('status')
                    ->label(__('lba.commerce.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.invoice_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Invoice::STATUS_PAID => 'success',
                        Invoice::STATUS_VOID => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('issued_at')
                    ->label(__('lba.commerce.orders.issued_at'))
                    ->dateTime(),
                TextColumn::make('paid_at')
                    ->label(__('lba.commerce.orders.invoice_paid_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->paginated(false);
    }
}
