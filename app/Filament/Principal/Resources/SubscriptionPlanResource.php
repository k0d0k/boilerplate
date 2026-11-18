<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\SubscriptionPlanResource\Pages\CreateSubscriptionPlan;
use App\Filament\Principal\Resources\SubscriptionPlanResource\Pages\EditSubscriptionPlan;
use App\Filament\Principal\Resources\SubscriptionPlanResource\Pages\ListSubscriptionPlans;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS4 — Principal subscription plan catalogue.
 *
 * A plan is a COMMERCIAL licence template only: price, billing interval, trial
 * length, seat limit and the installed modules it licenses. It is never a
 * tenant and holds no clinical data. Plans are archived, never deleted, so a
 * historical licence keeps resolving its plan identity.
 *
 * Authorization is delegated to {@see AuthorizesPrincipalResources}: viewing
 * requires `platform.plan.manage` OR `platform.subscription.view`; changing a
 * plan requires `platform.plan.manage`.
 *
 * @extends resource<SubscriptionPlan>
 */
class SubscriptionPlanResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = SubscriptionPlan::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 3;

    /**
     * The subscription-plan catalogue is served canonically by the TailAdmin
     * surface (`principal.tailadmin.subscriptions.*`), so this interim Filament
     * screen keeps its routes but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('lba.subscriptions.plan_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.nav.principal.subscriptions');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.subscriptions.section_identity'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('lba.subscriptions.code'))
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'subscription_plans', column: 'code', ignoreRecord: true),
                        TextInput::make('name')
                            ->label(__('lba.subscriptions.name'))
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->label(__('lba.subscriptions.status'))
                            ->options(static::statusOptions())
                            ->default(SubscriptionPlan::STATUS_ACTIVE)
                            ->required(),
                        Textarea::make('description')
                            ->label(__('lba.subscriptions.description'))
                            ->rows(2)
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('lba.subscriptions.section_pricing'))
                    ->schema([
                        TextInput::make('price_amount')
                            ->label(__('lba.subscriptions.price_amount'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0),
                        Select::make('currency')
                            ->label(__('lba.subscriptions.currency'))
                            ->options(static::currencyOptions())
                            ->default(Currency::IDR)
                            ->required(),
                        Select::make('billing_interval')
                            ->label(__('lba.subscriptions.billing_interval'))
                            ->options(static::intervalOptions())
                            ->default(SubscriptionPlan::INTERVAL_MONTHLY)
                            ->required(),
                        TextInput::make('trial_days')
                            ->label(__('lba.subscriptions.trial_days'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('seat_limit')
                            ->label(__('lba.subscriptions.seat_limit'))
                            ->numeric()
                            ->minValue(1)
                            ->helperText(__('lba.subscriptions.seat_limit_help')),
                    ])
                    ->columns(2),
                Section::make(__('lba.subscriptions.section_modules'))
                    ->description(__('lba.subscriptions.section_modules_notice'))
                    ->schema([
                        CheckboxList::make('module_keys')
                            ->label(__('lba.subscriptions.module_keys'))
                            ->options(static::moduleOptions())
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.subscriptions.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('lba.subscriptions.code'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.subscriptions.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.subscriptions.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        SubscriptionPlan::STATUS_ACTIVE => 'success',
                        SubscriptionPlan::STATUS_ARCHIVED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('price_amount')
                    ->label(__('lba.subscriptions.price'))
                    ->state(fn (SubscriptionPlan $record): string => Currency::format($record->price_amount, $record->currency)),
                TextColumn::make('billing_interval')
                    ->label(__('lba.subscriptions.billing_interval'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.subscriptions.interval_{$state}")),
                TextColumn::make('trial_days')
                    ->label(__('lba.subscriptions.trial_days'))
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('subscriptions_count')
                    ->label(__('lba.subscriptions.tenants'))
                    ->counts('subscriptions')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.subscriptions.filter_status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE)),
                Action::make('archive')
                    ->label(__('lba.subscriptions.archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->visible(fn (SubscriptionPlan $record): bool => static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE)
                        && $record->status === SubscriptionPlan::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionPlan $record) => $record->update(['status' => SubscriptionPlan::STATUS_ARCHIVED])),
                Action::make('reactivate')
                    ->label(__('lba.subscriptions.reactivate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (SubscriptionPlan $record): bool => static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE)
                        && $record->status !== SubscriptionPlan::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionPlan $record) => $record->update(['status' => SubscriptionPlan::STATUS_ACTIVE])),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedCreditCard)
            ->emptyStateHeading(__('lba.subscriptions.empty_heading'))
            ->emptyStateDescription(__('lba.subscriptions.empty_description'));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionPlans::route('/'),
            'create' => CreateSubscriptionPlan::route('/create'),
            'edit' => EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE)
            || static::principalCan(PermissionKey::PLATFORM_SUBSCRIPTION_VIEW);
    }

    public static function canCreate(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Plans are archived, never destroyed — historical licences must keep
        // resolving their plan identity.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        return [
            SubscriptionPlan::STATUS_ACTIVE => __('lba.subscriptions.status_active'),
            SubscriptionPlan::STATUS_ARCHIVED => __('lba.subscriptions.status_archived'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function currencyOptions(): array
    {
        $options = [];

        foreach (Currency::SUPPORTED as $code) {
            $options[$code] = $code;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function intervalOptions(): array
    {
        return [
            SubscriptionPlan::INTERVAL_MONTHLY => __('lba.subscriptions.interval_monthly'),
            SubscriptionPlan::INTERVAL_YEARLY => __('lba.subscriptions.interval_yearly'),
        ];
    }

    /**
     * Installed, trusted modules that a plan may license, keyed by module key.
     *
     * @return array<string, string>
     */
    protected static function moduleOptions(): array
    {
        return RegisteredModule::query()
            ->where('is_installed', true)
            ->orderBy('name')
            ->pluck('name', 'module_key')
            ->all();
    }
}
