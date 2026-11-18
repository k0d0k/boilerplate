<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Consultations\Filament\Resources\ConsultationResource;

/**
 * Workstream 2 — the SINA AI consultation transcript.
 *
 * Read-only in the clinic panel: messages are written by the SINA chat service
 * in the patient portal, and the clinic reviews the full, immutable history
 * including the provider, model and billed credits of every turn.
 */
class ConsultationMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.consultations.messages.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ConsultationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('lba.consultations.messages.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('role')
                    ->label(__('lba.consultations.messages.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.consultations.messages.role_{$state}")),
                TextColumn::make('content')
                    ->label(__('lba.consultations.messages.content'))
                    ->wrap()
                    ->limit(160),
                TextColumn::make('provider')
                    ->label(__('lba.consultations.messages.provider'))
                    ->placeholder('—'),
                TextColumn::make('model')
                    ->label(__('lba.consultations.messages.model'))
                    ->placeholder('—'),
                TextColumn::make('credits')
                    ->label(__('lba.consultations.messages.credits'))
                    ->numeric()
                    ->placeholder('0'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('lba.consultations.messages.empty'));
    }
}
