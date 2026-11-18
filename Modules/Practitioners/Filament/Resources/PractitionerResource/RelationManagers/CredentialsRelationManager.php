<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\PractitionerCredential;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * WS7.A — a practitioner's professional credentials.
 *
 * Credentials are added, verified, rejected and revoked here; every write goes
 * through {@see PractitionerRegistry} so the permission is re-derived
 * server-side and an audited, non-self decision is recorded. The raw licence
 * reference is never displayed — only its masked tail.
 */
class CredentialsRelationManager extends RelationManager
{
    protected static string $relationship = 'credentials';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.practitioners.credentials.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PractitionerResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('credential_type')
                    ->label(__('lba.practitioners.credentials.type'))
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.credentials.type_{$state}")),
                TextColumn::make('jurisdiction')
                    ->label(__('lba.practitioners.credentials.jurisdiction'))
                    ->placeholder('—'),
                TextColumn::make('reference_number')
                    ->label(__('lba.practitioners.credentials.reference'))
                    ->getStateUsing(fn (PractitionerCredential $record): string => $record->maskedReference())
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.practitioners.credentials.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.credentials.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        PractitionerCredential::STATUS_VERIFIED => 'success',
                        PractitionerCredential::STATUS_PENDING => 'warning',
                        PractitionerCredential::STATUS_REJECTED,
                        PractitionerCredential::STATUS_REVOKED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('issued_at')
                    ->label(__('lba.practitioners.credentials.issued_at'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label(__('lba.practitioners.credentials.expires_at'))
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('verified_at')
                    ->label(__('lba.practitioners.credentials.verified_at'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('lba.practitioners.credentials.add'))
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->visible(fn (): bool => PractitionerResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        Select::make('credential_type')
                            ->label(__('lba.practitioners.credentials.type'))
                            ->options(static::typeOptions())
                            ->required(),
                        TextInput::make('jurisdiction')
                            ->label(__('lba.practitioners.credentials.jurisdiction'))
                            ->maxLength(64),
                        TextInput::make('reference_number')
                            ->label(__('lba.practitioners.credentials.reference'))
                            ->helperText(__('lba.practitioners.credentials.reference_hint'))
                            ->maxLength(128),
                        DatePicker::make('issued_at')
                            ->label(__('lba.practitioners.credentials.issued_at')),
                        DatePicker::make('expires_at')
                            ->label(__('lba.practitioners.credentials.expires_at')),
                    ])
                    ->action(function (array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->addCredential(
                                $actor,
                                $this->getOwnerRecord(),
                                $data,
                            ),
                            __('lba.practitioners.credentials.added'),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label(__('lba.practitioners.credentials.verify'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->visible(fn (PractitionerCredential $record): bool => PractitionerResource::canVerify() && $record->isPending())
                    ->requiresConfirmation()
                    ->schema([
                        DatePicker::make('expires_at')
                            ->label(__('lba.practitioners.credentials.expires_at')),
                        Textarea::make('notes')
                            ->label(__('lba.practitioners.credentials.notes'))
                            ->rows(2),
                    ])
                    ->action(function (PractitionerCredential $record, array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->verifyCredential(
                                $actor,
                                $record,
                                isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                                $data['notes'] ?? null,
                            ),
                            __('lba.practitioners.credentials.verified'),
                        );
                    }),
                Action::make('reject')
                    ->label(__('lba.practitioners.credentials.reject'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (PractitionerCredential $record): bool => PractitionerResource::canVerify() && $record->isPending())
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('lba.practitioners.credentials.notes'))
                            ->rows(2),
                    ])
                    ->action(function (PractitionerCredential $record, array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->rejectCredential(
                                $actor,
                                $record,
                                $data['notes'] ?? null,
                            ),
                            __('lba.practitioners.credentials.rejected'),
                        );
                    }),
                Action::make('revoke')
                    ->label(__('lba.practitioners.credentials.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (PractitionerCredential $record): bool => PractitionerResource::canVerify() && $record->isVerified())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.credentials.revoke_confirm'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('lba.practitioners.credentials.notes'))
                            ->rows(2),
                    ])
                    ->action(function (PractitionerCredential $record, array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->revokeCredential(
                                $actor,
                                $record,
                                $data['notes'] ?? null,
                            ),
                            __('lba.practitioners.credentials.revoked'),
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('lba.practitioners.credentials.empty'));
    }

    /**
     * @return array<string, string>
     */
    protected static function typeOptions(): array
    {
        $options = [];

        foreach (PractitionerCredential::TYPES as $type) {
            $options[$type] = __("lba.practitioners.credentials.type_{$type}");
        }

        return $options;
    }
}
