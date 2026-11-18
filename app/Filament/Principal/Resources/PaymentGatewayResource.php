<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\PaymentGatewayResource\Pages\CreatePaymentGateway;
use App\Filament\Principal\Resources\PaymentGatewayResource\Pages\EditPaymentGateway;
use App\Filament\Principal\Resources\PaymentGatewayResource\Pages\ListPaymentGateways;
use App\Models\PaymentGateway;
use App\Platform\Access\PermissionKey;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Payments\PaymentManager;
use Modules\Commerce\Support\Currency;
use UnitEnum;

/**
 * WS3 — Principal payment gateway configuration.
 *
 * Payment configuration is CENTRAL and platform-wide: it is administered here,
 * in the Principal panel, and never per tenant. Each provider has one row whose
 * credentials are ENCRYPTED at rest and never displayed again — an operator can
 * only replace a secret, never read it back.
 *
 * Two safety rules are enforced in the UI and mirrored by the PaymentManager:
 *  - a gateway cannot be verified until every required credential is present;
 *  - live charges stay refused until the provider row is enabled, in `live`
 *    mode, verified AND the platform operations switch is on.
 *
 * @extends resource<PaymentGateway>
 */
class PaymentGatewayResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = PaymentGateway::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 5;

    /**
     * Payment-provider governance is served canonically by the TailAdmin surface
     * (`principal.tailadmin.payment-providers.*`), so this interim Filament
     * screen keeps its routes but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getModelLabel(): string
    {
        return __('lba.payments.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.payments.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.payments.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.payments.section_gateway'))
                    ->schema([
                        Select::make('provider')
                            ->label(__('lba.payments.provider'))
                            ->options(static::providerOptions())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->live()
                            ->disabled(fn (?PaymentGateway $record): bool => $record !== null)
                            ->dehydrated()
                            ->helperText(__('lba.payments.provider_help')),
                        TextInput::make('label')
                            ->label(__('lba.payments.label'))
                            ->required()
                            ->maxLength(255),
                        Select::make('mode')
                            ->label(__('lba.payments.mode'))
                            ->options(static::modeOptions())
                            ->default(PaymentGateway::MODE_SANDBOX)
                            ->required(),
                        Toggle::make('enabled')
                            ->label(__('lba.payments.enabled'))
                            ->helperText(__('lba.payments.enabled_help'))
                            ->default(false),
                    ])
                    ->columns(2),
                Section::make(__('lba.payments.section_credentials'))
                    ->description(__('lba.payments.credentials_notice'))
                    ->schema([
                        TextInput::make('credential_server_key')
                            ->label(__('lba.payments.credential_server_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'midtrans'),
                        TextInput::make('credential_client_key')
                            ->label(__('lba.payments.credential_client_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'midtrans'),
                        TextInput::make('credential_api_key')
                            ->label(__('lba.payments.credential_api_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'ipaymu'),
                        TextInput::make('credential_va')
                            ->label(__('lba.payments.credential_va'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'ipaymu'),
                        TextInput::make('credential_client_id')
                            ->label(__('lba.payments.credential_client_id'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'paypal'),
                        TextInput::make('credential_client_secret')
                            ->label(__('lba.payments.credential_client_secret'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'paypal'),
                        TextInput::make('config_webhook_id')
                            ->label(__('lba.payments.config_webhook_id'))
                            ->helperText(__('lba.payments.config_webhook_id_help'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('provider') === 'paypal'),
                    ])
                    ->columns(2),
                Section::make(__('lba.payments.section_availability'))
                    ->schema([
                        CheckboxList::make('currencies')
                            ->label(__('lba.payments.currencies'))
                            ->options(static::currencyOptions())
                            ->helperText(__('lba.payments.currencies_help'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label(__('lba.payments.label'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('lba.payments.provider'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('mode')
                    ->label(__('lba.payments.mode'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.payments.mode_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        PaymentGateway::MODE_LIVE => 'success',
                        default => 'warning',
                    }),
                IconColumn::make('enabled')
                    ->label(__('lba.payments.enabled'))
                    ->boolean(),
                TextColumn::make('configured')
                    ->label(__('lba.payments.configured'))
                    ->badge()
                    ->state(fn (PaymentGateway $record): string => static::isConfigured($record)
                        ? __('lba.payments.configured_yes')
                        : __('lba.payments.configured_no'))
                    ->color(fn (PaymentGateway $record): string => static::isConfigured($record) ? 'success' : 'danger'),
                TextColumn::make('verified_at')
                    ->label(__('lba.payments.verified'))
                    ->badge()
                    ->state(fn (PaymentGateway $record): string => $record->isVerified()
                        ? __('lba.payments.verified_yes')
                        : __('lba.payments.verified_no'))
                    ->color(fn (PaymentGateway $record): string => $record->isVerified() ? 'success' : 'gray'),
                TextColumn::make('currencies')
                    ->label(__('lba.payments.currencies'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('verify')
                    ->label(__('lba.payments.verify'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.payments.verify_description'))
                    ->visible(fn (PaymentGateway $record): bool => ! $record->isVerified())
                    ->action(function (PaymentGateway $record): void {
                        if (! static::isConfigured($record)) {
                            Notification::make()
                                ->title(__('lba.payments.not_configured_notice'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->forceFill(['verified_at' => now()])->save();

                        Notification::make()
                            ->title(__('lba.payments.verified_notice'))
                            ->success()
                            ->send();
                    }),
                Action::make('unverify')
                    ->label(__('lba.payments.unverify'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PaymentGateway $record): bool => $record->isVerified())
                    ->action(function (PaymentGateway $record): void {
                        $record->forceFill(['verified_at' => null])->save();

                        Notification::make()
                            ->title(__('lba.payments.unverified_notice'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('label')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('lba.payments.empty_heading'))
            ->emptyStateDescription(__('lba.payments.empty_description'));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPaymentGateways::route('/'),
            'create' => CreatePaymentGateway::route('/create'),
            'edit' => EditPaymentGateway::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PAYMENT_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PAYMENT_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_PAYMENT_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Gateway configuration is disabled, never destroyed, so a verified
        // credential set is never lost by accident.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Assemble the encrypted credential payload and non-secret config from the
     * flat form fields. A blank secret keeps the stored value, so editing a
     * gateway never requires re-entering credentials.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareData(array $data, ?PaymentGateway $record): array
    {
        $provider = (string) ($data['provider'] ?? $record?->provider ?? '');
        $credentials = (array) ($record?->credentials ?? []);

        foreach ((array) config("payments.gateways.{$provider}.credentials", []) as $key) {
            $field = 'credential_'.$key;
            $value = $data[$field] ?? null;
            unset($data[$field]);

            if (is_string($value) && trim($value) !== '') {
                $credentials[$key] = trim($value);
            }
        }

        $data['credentials'] = $credentials;

        $config = (array) ($record?->config ?? []);

        if (array_key_exists('config_webhook_id', $data)) {
            $webhookId = $data['config_webhook_id'] ?? null;
            unset($data['config_webhook_id']);
            $config['webhook_id'] = is_string($webhookId) && trim($webhookId) !== '' ? trim($webhookId) : null;
        }

        $data['config'] = $config;

        /** @var array<int, string> $currencies */
        $currencies = array_values(array_filter((array) ($data['currencies'] ?? [])));
        $data['currencies'] = $currencies === [] ? null : $currencies;

        return $data;
    }

    /**
     * True when the provider row holds every credential its driver needs.
     */
    public static function isConfigured(PaymentGateway $record): bool
    {
        $provider = (string) $record->provider;

        if (! is_string(config("payments.gateways.{$provider}.driver"))) {
            return false;
        }

        return app(PaymentManager::class)->driver($provider)->isConfigured($record);
    }

    /**
     * @return array<string, string>
     */
    protected static function providerOptions(): array
    {
        return app(PaymentManager::class)->providerOptions();
    }

    /**
     * @return array<string, string>
     */
    protected static function modeOptions(): array
    {
        return [
            PaymentGateway::MODE_SANDBOX => __('lba.payments.mode_sandbox'),
            PaymentGateway::MODE_LIVE => __('lba.payments.mode_live'),
        ];
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
