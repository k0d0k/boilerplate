<?php

namespace App\Filament\Principal\Resources\TenantResource\RelationManagers;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Models\Tenant;
use App\Platform\Access\PermissionKey;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Subscriptions\SubscriptionLicensing;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * WS4 — a tenant's subscriptions / licences (Platform governance).
 *
 * Binds the tenant to a subscription plan through {@see SubscriptionLicensing},
 * the SAME write path that installs and subscription-backs the module
 * entitlements the FeatureGate verifies. Viewing requires
 * `platform.subscription.view`; mutating requires `platform.billing.manage`.
 *
 * Cancellation is non-destructive: it never touches a tenant database; the
 * FeatureGate simply fails closed once the subscription is no longer active.
 */
class SubscriptionsRelationManager extends RelationManager
{
    use AuthorizesPrincipalResources;

    protected static string $relationship = 'subscriptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.subscriptions.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_SUBSCRIPTION_VIEW);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan.name')
                    ->label(__('lba.subscriptions.plan'))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('lba.subscriptions.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.subscriptions.sub_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        TenantSubscription::STATUS_ACTIVE => 'success',
                        TenantSubscription::STATUS_TRIALING => 'info',
                        TenantSubscription::STATUS_CANCELLED, TenantSubscription::STATUS_EXPIRED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('seats')
                    ->label(__('lba.subscriptions.seats'))
                    ->numeric(),
                TextColumn::make('current_period_end')
                    ->label(__('lba.subscriptions.period_end'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('reference')
                    ->label(__('lba.subscriptions.reference'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('subscribe')
                    ->label(__('lba.subscriptions.subscribe'))
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->visible(fn (): bool => static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE))
                    ->schema([
                        Select::make('plan_id')
                            ->label(__('lba.subscriptions.plan'))
                            ->options($this->activePlanOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('seats')
                            ->label(__('lba.subscriptions.seats'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->subscribe($data);
                    }),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label(__('lba.subscriptions.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (TenantSubscription $record): bool => static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
                        && ! $record->isActive())
                    ->requiresConfirmation()
                    ->action(function (TenantSubscription $record): void {
                        app(SubscriptionLicensing::class)->activate($record, auth()->user());
                        Notification::make()->success()->title(__('lba.subscriptions.activated'))->send();
                    }),
                Action::make('cancel')
                    ->label(__('lba.subscriptions.cancel'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (TenantSubscription $record): bool => static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
                        && $record->cancelled_at === null)
                    ->requiresConfirmation()
                    ->action(function (TenantSubscription $record): void {
                        app(SubscriptionLicensing::class)->cancel($record);
                        Notification::make()->success()->title(__('lba.subscriptions.cancelled'))->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('lba.subscriptions.empty_heading'))
            ->emptyStateDescription(__('lba.subscriptions.empty_description'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function subscribe(array $data): void
    {
        $tenant = $this->getOwnerRecord();
        $plan = SubscriptionPlan::query()->find((string) ($data['plan_id'] ?? ''));

        if (! $tenant instanceof Tenant || ! $plan instanceof SubscriptionPlan) {
            Notification::make()->danger()->title(__('lba.subscriptions.subscribe_failed'))->send();

            return;
        }

        app(SubscriptionLicensing::class)->subscribe(
            $tenant,
            $plan,
            (int) ($data['seats'] ?? 1),
            auth()->user(),
        );

        Notification::make()->success()->title(__('lba.subscriptions.subscribed'))->send();
    }

    /**
     * @return array<string, string>
     */
    private function activePlanOptions(): array
    {
        return SubscriptionPlan::query()
            ->where('status', SubscriptionPlan::STATUS_ACTIVE)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
