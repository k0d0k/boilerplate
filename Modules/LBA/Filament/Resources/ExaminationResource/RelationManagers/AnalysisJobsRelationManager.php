<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\LBA\AI\Exceptions\AiAnalysisException;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\AnalysisJob;
use Modules\LBA\Models\Examination;

/**
 * Phase 5 — asynchronous AI vision analysis jobs of an examination.
 *
 * Read-only except for an explicit, authorized, idempotent RETRY of a FAILED
 * job. Every value shown is real gateway/model output: a job is never displayed
 * as successful unless it actually completed, and a completed job's detections
 * remain UNREVIEWED SUGGESTIONS — nothing is approved or published here.
 */
class AnalysisJobsRelationManager extends RelationManager
{
    protected static string $relationship = 'analysisJobs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.exams.ai.jobs_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ExaminationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label(__('lba.exams.ai.job_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.exams.ai.status_{$state}"))
                    ->color(fn (AnalysisJob $record): string => AnalysisJob::statusColor((string) $record->status)),
                TextColumn::make('provider')
                    ->label(__('lba.exams.ai.provider'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('model_name')
                    ->label(__('lba.exams.ai.model'))
                    ->formatStateUsing(fn (?string $state, AnalysisJob $record): string => trim(sprintf(
                        '%s %s',
                        (string) $state,
                        (string) ($record->model_version ?? ''),
                    )) ?: '—'),
                TextColumn::make('attempts')
                    ->label(__('lba.exams.ai.attempts'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('error_code')
                    ->label(__('lba.exams.ai.error'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('lba.exams.ai.queued_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label(__('lba.exams.ai.completed_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('retryAnalysis')
                    ->label(__('lba.exams.ai.retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (AnalysisJob $record): bool => $record->isRetryable()
                        && ExaminationResource::canRetryAiAnalysis($this->getOwnerRecord()))
                    ->action(function (AnalysisJob $record): void {
                        try {
                            app(AiAnalysisService::class)->retryAnalysis($record, ExaminationResource::currentActor());

                            Notification::make()
                                ->success()
                                ->title(__('lba.exams.ai.retry_queued'))
                                ->send();
                        } catch (AiAnalysisException $exception) {
                            Notification::make()
                                ->danger()
                                ->title($exception->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('lba.exams.ai.jobs_empty'));
    }
}
