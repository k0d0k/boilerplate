<?php

namespace Modules\Commerce\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Modules\Commerce\Models\Invoice;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS3 — Clinic panel invoice register (read-only).
 *
 * An invoice is created by checkout and updated only by settlement; it is never
 * edited or deleted here. The list is scoped to the branches the acting identity
 * may operate in, so another branch's invoices are never visible.
 *
 * @extends resource<Invoice>
 */
class InvoiceResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 46;

    protected static ?string $recordTitleAttribute = 'number';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.invoices.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.invoices.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.invoices.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('lba.commerce.invoices.number'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('order.order_number')
                    ->label(__('lba.commerce.orders.number'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(__('lba.commerce.invoices.amount'))
                    ->formatStateUsing(fn (Invoice $record): string => Currency::format((float) $record->amount, (string) $record->currency))
                    ->sortable(),
                TextColumn::make('currency')
                    ->label(__('lba.commerce.invoices.currency'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.commerce.invoices.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.invoice_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Invoice::STATUS_PAID => 'success',
                        Invoice::STATUS_VOID => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('issued_at')
                    ->label(__('lba.commerce.invoices.issued_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label(__('lba.commerce.invoices.paid_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.commerce.invoices.status'))
                    ->options([
                        Invoice::STATUS_ISSUED => __('lba.commerce.orders.invoice_status_issued'),
                        Invoice::STATUS_PAID => __('lba.commerce.orders.invoice_status_paid'),
                        Invoice::STATUS_VOID => __('lba.commerce.orders.invoice_status_void'),
                    ]),
            ])
            ->recordActions([
                Action::make('order')
                    ->label(__('lba.commerce.invoices.view_order'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Invoice $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->visible(fn (Invoice $record): bool => $record->order_id !== null),
            ])
            ->defaultSort('issued_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('lba.commerce.invoices.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.invoices.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        $branches = static::accessibleBranchIds();

        return parent::getEloquentQuery()->when(
            $branches !== [],
            fn (Builder $query): Builder => $query->whereHas('order', fn (Builder $inner): Builder => $inner->whereIn('branch_id', $branches)),
            fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::ORDER_MANAGE);
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
}
