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
use Modules\Commerce\Filament\Resources\PaymentTransactionResource\Pages\ListTransactions;
use Modules\Commerce\Models\PaymentTransaction;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS3 — Clinic panel transaction history (read-only, append-only).
 *
 * Every payment movement across the tenant, newest first. Scoped to the branches
 * the acting identity may operate in. Transactions are never edited or deleted —
 * a failed attempt stays as history.
 *
 * @extends resource<PaymentTransaction>
 */
class PaymentTransactionResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = PaymentTransaction::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 47;

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.transactions.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.transactions.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.transactions.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')
                    ->label(__('lba.commerce.orders.number'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('provider')
                    ->label(__('lba.commerce.transactions.provider'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('provider_reference')
                    ->label(__('lba.commerce.transactions.reference'))
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('amount')
                    ->label(__('lba.commerce.transactions.amount'))
                    ->formatStateUsing(fn (PaymentTransaction $record): string => Currency::format((float) $record->amount, (string) $record->currency))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('lba.commerce.transactions.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.payment_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        PaymentTransaction::STATUS_SUCCEEDED => 'success',
                        PaymentTransaction::STATUS_FAILED, PaymentTransaction::STATUS_EXPIRED => 'danger',
                        PaymentTransaction::STATUS_REFUNDED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label(__('lba.commerce.transactions.recorded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.commerce.transactions.status'))
                    ->options([
                        PaymentTransaction::STATUS_INITIATED => __('lba.commerce.orders.payment_status_initiated'),
                        PaymentTransaction::STATUS_PENDING => __('lba.commerce.orders.payment_status_pending'),
                        PaymentTransaction::STATUS_SUCCEEDED => __('lba.commerce.orders.payment_status_succeeded'),
                        PaymentTransaction::STATUS_FAILED => __('lba.commerce.orders.payment_status_failed'),
                        PaymentTransaction::STATUS_EXPIRED => __('lba.commerce.orders.payment_status_expired'),
                        PaymentTransaction::STATUS_REFUNDED => __('lba.commerce.orders.payment_status_refunded'),
                    ]),
            ])
            ->recordActions([
                Action::make('order')
                    ->label(__('lba.commerce.transactions.view_order'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (PaymentTransaction $record): string => OrderResource::getUrl('view', ['record' => $record->order_id]))
                    ->visible(fn (PaymentTransaction $record): bool => $record->order_id !== null),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->emptyStateHeading(__('lba.commerce.transactions.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.transactions.empty_description'));
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
            'index' => ListTransactions::route('/'),
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
