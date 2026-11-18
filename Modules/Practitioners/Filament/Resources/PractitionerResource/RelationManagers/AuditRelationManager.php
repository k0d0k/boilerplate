<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Practitioners\Filament\Resources\PractitionerResource;

/**
 * WS7.A — the practitioner's append-only audit trail.
 *
 * Read-only: it answers "who changed this practitioner, and when" — every
 * registration, profile edit, verification, suspension, credential and branch
 * decision is recorded here with the acting identity and timestamp.
 */
class AuditRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.practitioners.audit.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PractitionerResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = User::query()->pluck('name', 'id')->all();

        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('lba.practitioners.audit.occurred_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->label(__('lba.practitioners.audit.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.audit.action_{$state}")),
                TextColumn::make('actor_id')
                    ->label(__('lba.practitioners.audit.actor'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading(__('lba.practitioners.audit.empty'));
    }
}
