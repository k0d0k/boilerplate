<?php

namespace Modules\Commerce\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Filament\Resources\StockLevelResource\Pages\ListStockLevels;
use Modules\Commerce\Models\InventoryStock;
use Modules\Commerce\Services\InventoryService;

/**
 * WS3 — Clinic panel branch stock levels.
 *
 * Operates on the verified current tenant's isolated database and is further
 * scoped to the branches the acting identity may operate in. Stock only ever
 * changes through {@see InventoryService}, so a level can never go negative.
 * Stock rows are created implicitly by receiving inventory, so there is no
 * create page and no delete.
 *
 * @extends resource<InventoryStock>
 */
class StockLevelResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = InventoryStock::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 42;

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.inventory.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.inventory.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.inventory.nav');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label(__('lba.commerce.inventory.product'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('branch.name')
                    ->label(__('lba.commerce.inventory.branch'))
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('lba.commerce.inventory.quantity'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reorder_level')
                    ->label(__('lba.commerce.inventory.reorder_level'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('low')
                    ->label(__('lba.commerce.inventory.low'))
                    ->state(fn (InventoryStock $record): bool => $record->isLow())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label(__('lba.commerce.inventory.filter_branch'))
                    ->options(static::accessibleBranchOptions()),
            ])
            ->recordActions([
                static::receiveAction(),
                static::adjustAction(),
            ])
            ->defaultSort('quantity')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->emptyStateHeading(__('lba.commerce.inventory.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.inventory.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        $branches = static::accessibleBranchIds();

        return parent::getEloquentQuery()->when(
            $branches !== [],
            fn (Builder $query): Builder => $query->whereIn('branch_id', $branches),
            fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockLevels::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    protected static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label(__('lba.commerce.inventory.receive'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn (): bool => static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE))
            ->schema([
                TextInput::make('quantity')
                    ->label(__('lba.commerce.inventory.add_quantity'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('reason')
                    ->label(__('lba.commerce.inventory.reason'))
                    ->maxLength(255),
            ])
            ->action(function (InventoryStock $record, array $data): void {
                app(InventoryService::class)->receive(
                    $record->product,
                    (string) $record->branch_id,
                    (int) $data['quantity'],
                    static::currentUser(),
                    $data['reason'] ?? null,
                );

                Notification::make()->success()->title(__('lba.commerce.inventory.received'))->send();
            });
    }

    protected static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('lba.commerce.inventory.adjust'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('warning')
            ->visible(fn (): bool => static::moduleActive() && static::allows(PermissionKey::COMMERCE_MANAGE))
            ->fillForm(fn (InventoryStock $record): array => ['quantity' => (int) $record->quantity])
            ->schema([
                TextInput::make('quantity')
                    ->label(__('lba.commerce.inventory.new_quantity'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('reason')
                    ->label(__('lba.commerce.inventory.reason'))
                    ->maxLength(255),
            ])
            ->action(function (InventoryStock $record, array $data): void {
                try {
                    app(InventoryService::class)->adjustTo(
                        $record->product,
                        (string) $record->branch_id,
                        (int) $data['quantity'],
                        static::currentUser(),
                        $data['reason'] ?? null,
                    );

                    Notification::make()->success()->title(__('lba.commerce.inventory.adjusted'))->send();
                } catch (InventoryException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }
}
