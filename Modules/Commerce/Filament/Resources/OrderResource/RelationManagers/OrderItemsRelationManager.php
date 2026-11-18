<?php

namespace Modules\Commerce\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Models\OrderItem;
use Modules\Commerce\Support\Currency;

/**
 * WS3 — the immutable lines of a single order.
 */
class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.commerce.orders.items_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return OrderResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.commerce.orders.item_name'))
                    ->searchable(),
                TextColumn::make('sku')
                    ->label(__('lba.commerce.orders.item_sku'))
                    ->placeholder('—'),
                TextColumn::make('unit_price')
                    ->label(__('lba.commerce.orders.unit_price'))
                    ->formatStateUsing(fn (OrderItem $record): string => Currency::format((float) $record->unit_price, (string) $record->currency)),
                TextColumn::make('quantity')
                    ->label(__('lba.commerce.orders.quantity'))
                    ->numeric(),
                TextColumn::make('line_total')
                    ->label(__('lba.commerce.orders.line_total'))
                    ->formatStateUsing(fn (OrderItem $record): string => Currency::format((float) $record->line_total, (string) $record->currency)),
            ])
            ->paginated(false);
    }
}
