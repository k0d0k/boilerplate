<?php

namespace Modules\Commerce\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Filament\Resources\OrderResource\Pages\ListOrders;
use Modules\Commerce\Filament\Resources\OrderResource\Pages\ViewOrder;
use Modules\Commerce\Filament\Resources\OrderResource\RelationManagers\OrderInvoicesRelationManager;
use Modules\Commerce\Filament\Resources\OrderResource\RelationManagers\OrderItemsRelationManager;
use Modules\Commerce\Filament\Resources\OrderResource\RelationManagers\OrderTransactionsRelationManager;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Payments\Exceptions\PaymentException;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\RefundService;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS3 — Clinic panel orders and sales management.
 *
 * Operates on the verified current tenant's isolated database and is scoped to
 * the branches the acting identity may operate in. Orders are never deleted:
 * they are cancelled (returning reserved stock) or fulfilled. A fully settled
 * order is immutable.
 *
 * Online payment is initiated through {@see PaymentManager}, which resolves the
 * central gateway configuration and refuses live charges until the gateway is
 * configured, verified and the platform switch is on.
 *
 * @extends resource<Order>
 */
class OrderResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'commerce';

    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'order_number';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.commerce.orders.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.commerce.orders.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.commerce.orders.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label(__('lba.commerce.orders.number'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('branch.name')
                    ->label(__('lba.commerce.orders.branch'))
                    ->placeholder('—'),
                TextColumn::make('customer_name')
                    ->label(__('lba.commerce.orders.customer'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('patient.full_name')
                    ->label(__('lba.commerce.orders.patient'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('currency')
                    ->label(__('lba.commerce.orders.currency'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('grand_total')
                    ->label(__('lba.commerce.orders.total'))
                    ->formatStateUsing(fn (Order $record): string => Currency::format((float) $record->grand_total, (string) $record->currency))
                    ->sortable(),
                TextColumn::make('paid_total')
                    ->label(__('lba.commerce.orders.paid'))
                    ->formatStateUsing(fn (Order $record): string => Currency::format((float) $record->paid_total, (string) $record->currency))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.commerce.orders.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Order::STATUS_PAID => 'success',
                        Order::STATUS_PARTIALLY_PAID => 'warning',
                        Order::STATUS_CANCELLED, Order::STATUS_REFUNDED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('fulfillment_status')
                    ->label(__('lba.commerce.orders.fulfillment'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.commerce.orders.fulfillment_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Order::FULFILLMENT_FULFILLED => 'success',
                        Order::FULFILLMENT_CANCELLED, Order::FULFILLMENT_RETURNED => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('placed_at')
                    ->label(__('lba.commerce.orders.placed_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.commerce.orders.status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('branch_id')
                    ->label(__('lba.commerce.orders.branch'))
                    ->options(static::accessibleBranchOptions()),
                SelectFilter::make('source')
                    ->label(__('lba.commerce.orders.source'))
                    ->options(static::sourceOptions()),
                SelectFilter::make('currency')
                    ->label(__('lba.commerce.orders.currency'))
                    ->options(static::currencyOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                static::payOnlineAction(),
                static::fulfilAction(),
                static::refundAction(),
                static::cancelAction(),
            ])
            ->defaultSort('placed_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedReceiptPercent)
            ->emptyStateHeading(__('lba.commerce.orders.empty_heading'))
            ->emptyStateDescription(__('lba.commerce.orders.empty_description'));
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
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            OrderItemsRelationManager::class,
            OrderInvoicesRelationManager::class,
            OrderTransactionsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::ORDER_MANAGE);
    }

    public static function canCreate(): bool
    {
        // Orders are created at the POS / checkout, never by hand.
        return false;
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::ORDER_MANAGE);
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

    public static function canCancel(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::ORDER_MANAGE)
            && $record instanceof Order
            && $record->isOpen()
            && (float) $record->paid_total <= 0;
    }

    public static function canFulfil(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::ORDER_MANAGE)
            && $record instanceof Order
            && $record->isPaid()
            && $record->fulfillment_status === Order::FULFILLMENT_PENDING;
    }

    public static function canPayOnline(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::ORDER_MANAGE)
            && $record instanceof Order
            && $record->isOpen()
            && $record->outstanding() > 0;
    }

    public static function canRefund(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::ORDER_MANAGE)
            && $record instanceof Order
            && ! $record->isRefunded()
            && ! $record->isCancelled()
            && (float) $record->paid_total > 0;
    }

    /**
     * Cancel an order. The same authorisation predicate is re-checked
     * server-side before any state changes.
     */
    protected static function cancelAction(): Action
    {
        return Action::make('cancelOrder')
            ->label(__('lba.commerce.orders.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => static::canCancel($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('lba.commerce.orders.cancel_reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Order $record, array $data): void {
                if (! static::canCancel($record)) {
                    Notification::make()->danger()->title(__('lba.commerce.orders.unavailable'))->send();

                    return;
                }

                try {
                    app(OrderService::class)->cancel($record, static::currentUser(), $data['reason'] ?? null);

                    Notification::make()->success()->title(__('lba.commerce.orders.cancelled'))->send();
                } catch (OrderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    /**
     * Refund a settled order (in full) and return its reserved stock.
     */
    protected static function refundAction(): Action
    {
        return Action::make('refundOrder')
            ->label(__('lba.commerce.orders.refund'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => static::canRefund($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('lba.commerce.orders.refund_reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (Order $record, array $data): void {
                if (! static::canRefund($record)) {
                    Notification::make()->danger()->title(__('lba.commerce.orders.unavailable'))->send();

                    return;
                }

                try {
                    app(RefundService::class)->refund($record, null, static::currentUser(), $data['reason'] ?? null);

                    Notification::make()->success()->title(__('lba.commerce.orders.refunded'))->send();
                } catch (OrderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    protected static function fulfilAction(): Action
    {
        return Action::make('fulfilOrder')
            ->label(__('lba.commerce.orders.fulfil'))
            ->icon(Heroicon::OutlinedTruck)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Order $record): bool => static::canFulfil($record))
            ->action(function (Order $record): void {
                if (! static::canFulfil($record)) {
                    Notification::make()->danger()->title(__('lba.commerce.orders.unavailable'))->send();

                    return;
                }

                try {
                    app(OrderService::class)->markFulfilled($record);

                    Notification::make()->success()->title(__('lba.commerce.orders.fulfilled'))->send();
                } catch (OrderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    /**
     * Initiate a hosted charge for the outstanding balance with a configured
     * gateway, then hand the cashier the provider redirect URL.
     */
    protected static function payOnlineAction(): Action
    {
        return Action::make('payOnline')
            ->label(__('lba.commerce.orders.pay_online'))
            ->icon(Heroicon::OutlinedCreditCard)
            ->color('primary')
            ->visible(fn (Order $record): bool => static::canPayOnline($record))
            ->schema([
                Select::make('provider')
                    ->label(__('lba.commerce.orders.provider'))
                    ->options(fn (Order $record): array => app(PaymentManager::class)->availableFor((string) $record->currency))
                    ->required()
                    ->helperText(__('lba.commerce.orders.provider_help')),
            ])
            ->action(function (Order $record, array $data): void {
                if (! static::canPayOnline($record)) {
                    Notification::make()->danger()->title(__('lba.commerce.orders.unavailable'))->send();

                    return;
                }

                try {
                    $initiation = app(PaymentManager::class)->charge($record, (string) $data['provider']);

                    Notification::make()
                        ->success()
                        ->title(__('lba.commerce.orders.payment_started'))
                        ->body($initiation->redirectUrl)
                        ->persistent()
                        ->send();
                } catch (PaymentException|OrderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                }
            });
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Order::STATUSES as $status) {
            $options[$status] = __("lba.commerce.orders.status_{$status}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function sourceOptions(): array
    {
        $options = [];

        foreach (Order::SOURCES as $source) {
            $options[$source] = __("lba.commerce.orders.source_{$source}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $currency) {
            $options[$currency] = $currency;
        }

        return $options;
    }
}
