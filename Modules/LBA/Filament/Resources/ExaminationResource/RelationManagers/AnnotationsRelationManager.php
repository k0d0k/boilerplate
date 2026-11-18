<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\AnnotationRecorder;

/**
 * Phase 4 — annotations recorded on an examination.
 *
 * Mostly read-only: authoring and editing happen in the microscope workspace,
 * where coordinates are drawn against the image. This list lets a reviewer see
 * what was recorded and remove a manual annotation while the examination is
 * still editable. An annotation authored by a human carries `job_id = null`.
 */
class AnnotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'annotations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.exams.annotations.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ExaminationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('lba.exams.annotations.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.exams.annotations.type_{$state}"))
                    ->color('gray'),
                TextColumn::make('provenance')
                    ->label(__('lba.exams.annotations.provenance'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Annotation::PROVENANCE_AI
                        ? __('lba.exams.annotations.provenance_ai')
                        : __('lba.exams.annotations.provenance_human'))
                    ->color(fn (string $state): string => $state === Annotation::PROVENANCE_AI ? 'info' : 'success'),
                TextColumn::make('image_id')
                    ->label(__('lba.exams.annotations.image'))
                    ->formatStateUsing(fn (string $state): string => __('lba.exams.images.image_label', [
                        'ref' => strtoupper(substr($state, -6)),
                    ])),
                TextColumn::make('label')
                    ->label(__('lba.exams.annotations.label'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('notes')
                    ->label(__('lba.exams.annotations.notes'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('revision')
                    ->label(__('lba.exams.annotations.revision'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label(__('lba.exams.annotations.recorded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('deleteAnnotation')
                    ->label(__('lba.exams.annotations.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Annotation $record): bool => ExaminationResource::canEdit($this->getOwnerRecord())
                        && $record->isManual())
                    ->action(function (Annotation $record): void {
                        try {
                            app(AnnotationRecorder::class)->delete($record);

                            Notification::make()
                                ->success()
                                ->title(__('lba.exams.annotations.deleted'))
                                ->send();
                        } catch (ExaminationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading(__('lba.exams.annotations.empty'));
    }
}
