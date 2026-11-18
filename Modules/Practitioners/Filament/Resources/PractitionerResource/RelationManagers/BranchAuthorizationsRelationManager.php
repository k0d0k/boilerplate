<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\PractitionerBranchAuthorization;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * WS7.A — the branches a practitioner is authorized to practise at.
 *
 * Only the branches the acting identity may operate in are offered, and every
 * write goes through {@see PractitionerRegistry}, which re-derives the
 * authorization server-side and audits the change. Revocation is
 * non-destructive.
 */
class BranchAuthorizationsRelationManager extends RelationManager
{
    protected static string $relationship = 'branchAuthorizations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.practitioners.branches.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PractitionerResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch_id')
                    ->label(__('lba.practitioners.branches.branch'))
                    ->formatStateUsing(fn (?string $state): string => PractitionerResource::branchOptions()[$state] ?? '—'),
                TextColumn::make('status')
                    ->label(__('lba.practitioners.branches.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.branches.status_{$state}"))
                    ->color(fn (string $state): string => $state === PractitionerBranchAuthorization::STATUS_ACTIVE ? 'success' : 'gray'),
                TextColumn::make('authorized_at')
                    ->label(__('lba.practitioners.branches.authorized_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->label(__('lba.practitioners.branches.revoked_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('authorize')
                    ->label(__('lba.practitioners.branches.authorize'))
                    ->icon(Heroicon::OutlinedBuildingOffice)
                    ->visible(fn (): bool => PractitionerResource::canEdit($this->getOwnerRecord()))
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('lba.practitioners.branches.branch'))
                            ->options(static::branchChoices())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->authorizeBranch(
                                $actor,
                                $this->getOwnerRecord(),
                                (string) $data['branch_id'],
                            ),
                            __('lba.practitioners.branches.authorized'),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label(__('lba.practitioners.branches.revoke'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (PractitionerBranchAuthorization $record): bool => PractitionerResource::canEdit($this->getOwnerRecord()) && $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.branches.revoke_confirm'))
                    ->action(function (PractitionerBranchAuthorization $record): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->revokeBranch(
                                $actor,
                                $this->getOwnerRecord(),
                                (string) $record->branch_id,
                            ),
                            __('lba.practitioners.branches.revoked'),
                        );
                    }),
            ])
            ->emptyStateHeading(__('lba.practitioners.branches.empty'));
    }

    /**
     * Only the branches the identity may operate in can be authorized.
     *
     * @return array<string, string>
     */
    protected static function branchChoices(): array
    {
        return PractitionerResource::branchOptions();
    }
}
