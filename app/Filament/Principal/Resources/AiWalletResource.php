<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\AiWalletResource\Pages\ListAiWallets;
use App\Filament\Principal\Resources\AiWalletResource\Pages\ViewAiWallet;
use App\Filament\Principal\Resources\AiWalletResource\RelationManagers\AiLedgerRelationManager;
use App\Filament\Principal\Resources\AiWalletResource\RelationManagers\AiUsageRelationManager;
use App\Platform\Access\PermissionKey;
use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\AiBilling\Models\TenantAiWallet;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * WS4 — Principal view of the tenant AI (LBA) credit wallets.
 *
 * The wallet engine ({@see AiWalletService}) accounts in the CENTRAL database
 * only: no clinical image, patient identity or finding is ever stored on a
 * wallet, ledger entry or usage record. This resource therefore exposes the
 * platform-wide billing position — available / reserved / consumed credits per
 * tenant — plus a controlled, idempotent credit top-up.
 *
 * Viewing requires `platform.billing.manage` or `platform.analytics.view`;
 * granting credits requires `platform.billing.manage`. A wallet is created only
 * by the engine (first grant), never by hand, so this resource is read-only
 * apart from the grant action.
 *
 * @extends resource<TenantAiWallet>
 */
class AiWalletResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = TenantAiWallet::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 6;

    /**
     * AI credit governance is served canonically by the TailAdmin surface
     * (`principal.tailadmin.ai-credits.*`), so this interim Filament screen
     * keeps its routes but no longer adds a duplicate sidebar entry.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('lba.wallets.ai_single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.wallets.ai_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.wallets.nav_ai');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof TenantAiWallet
            ? ($record->tenant?->name ?? (string) $record->tenant_id)
            : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('lba.wallets.ai_plural'))
                ->schema([
                    TextEntry::make('tenant.name')->label(__('lba.wallets.tenant')),
                    TextEntry::make('available_credits')->label(__('lba.wallets.available')),
                    TextEntry::make('reserved_credits')->label(__('lba.wallets.reserved')),
                    TextEntry::make('lifetime_consumed_credits')->label(__('lba.wallets.consumed')),
                    TextEntry::make('total')->label(__('lba.wallets.total'))
                        ->state(fn (TenantAiWallet $record): int => $record->totalCredits()),
                    TextEntry::make('status')->label(__('lba.wallets.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __("lba.wallets.status_{$state}")),
                    TextEntry::make('currency')->label(__('lba.wallets.currency')),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->label(__('lba.wallets.tenant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('available_credits')
                    ->label(__('lba.wallets.available'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reserved_credits')
                    ->label(__('lba.wallets.reserved'))
                    ->numeric(),
                TextColumn::make('lifetime_consumed_credits')
                    ->label(__('lba.wallets.consumed'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('lba.wallets.total'))
                    ->state(fn (TenantAiWallet $record): int => $record->totalCredits()),
                TextColumn::make('status')
                    ->label(__('lba.wallets.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.wallets.status_{$state}"))
                    ->color(fn (string $state): string => $state === TenantAiWallet::STATUS_ACTIVE ? 'success' : 'danger'),
                TextColumn::make('currency')
                    ->label(__('lba.wallets.currency'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (TenantAiWallet $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                Action::make('grant')
                    ->label(__('lba.wallets.grant'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('success')
                    ->modalDescription(__('lba.wallets.grant_description'))
                    ->visible(fn (): bool => static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE))
                    ->schema([
                        TextInput::make('units')
                            ->label(__('lba.wallets.units'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('reason')
                            ->label(__('lba.wallets.reason'))
                            ->helperText(__('lba.wallets.reason_help'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (TenantAiWallet $record, array $data): void {
                        static::grant($record, (int) $data['units'], (string) $data['reason']);
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedCpuChip)
            ->emptyStateHeading(__('lba.wallets.empty_heading'))
            ->emptyStateDescription(__('lba.wallets.empty_description'));
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAiWallets::route('/'),
            'view' => ViewAiWallet::route('/{record}'),
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            AiUsageRelationManager::class,
            AiLedgerRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_BILLING_MANAGE)
            || static::principalCan(PermissionKey::PLATFORM_ANALYTICS_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    /**
     * Post an idempotent credit grant through the wallet engine.
     */
    protected static function grant(TenantAiWallet $wallet, int $units, string $reason): void
    {
        try {
            app(AiWalletService::class)->grant(
                (string) $wallet->tenant_id,
                $units,
                $reason,
                'principal-grant-'.Str::ulid()->toBase32(),
                static::principalUser()?->getKey(),
            );

            Notification::make()->success()->title(__('lba.wallets.granted'))->send();
        } catch (AiBillingException $exception) {
            Notification::make()->danger()->title(__('lba.wallets.grant_failed'))->body($exception->getMessage())->send();
        }
    }
}
