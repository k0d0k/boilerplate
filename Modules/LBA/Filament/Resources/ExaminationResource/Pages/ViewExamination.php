<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Pages\AssessmentForm;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Ingest\IngestCredentialIssuer;
use Modules\LBA\Models\Examination;

/**
 * Phase 4 — read-only examination view, including the image, annotation and
 * workflow-event relation managers.
 *
 * WS6.B adds the operator-facing "issue ingestion credential" action: an
 * authorized operator mints a SHORT-LIVED, scoped agent credential for THIS
 * examination. The raw secret is shown ONCE (it is never stored or logged in
 * plaintext — only its hash is persisted) and expires automatically.
 */
class ViewExamination extends ViewRecord
{
    protected static string $resource = ExaminationResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lba.exams.edit'))
                ->url(fn (Examination $record): string => static::getResource()::getUrl('edit', ['record' => $record]))
                ->visible(fn (Examination $record): bool => static::getResource()::canEdit($record)),
            Action::make('workspace')
                ->label(__('lba.exams.open_workspace'))
                ->icon(Heroicon::OutlinedViewfinderCircle)
                ->color('primary')
                ->url(fn (Examination $record): string => MicroscopeWorkspace::getUrl(['examination' => $record]))
                ->visible(fn (Examination $record): bool => static::getResource()::canView($record)),
            Action::make('assessment')
                ->label(__('lba.assessment.nav'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('warning')
                ->url(fn (Examination $record): string => AssessmentForm::getUrl(['examination' => $record]))
                ->visible(fn (Examination $record): bool => static::getResource()::canView($record)),
            Action::make('issueIngestCredential')
                ->label(__('lba.exams.ingest.issue'))
                ->icon(Heroicon::OutlinedArrowDownOnSquare)
                ->color('gray')
                ->visible(fn (Examination $record): bool => static::getResource()::canEdit($record))
                ->requiresConfirmation()
                ->modalDescription(__('lba.exams.ingest.issue_hint'))
                ->action(function (Examination $record): void {
                    $user = auth()->user();

                    $tenantId = $user instanceof User
                        ? app(ManagesTenantContext::class)->currentTenantId($user)
                        : null;

                    if (! $user instanceof User || $tenantId === null) {
                        Notification::make()
                            ->danger()
                            ->title(__('lba.exams.ingest.issue_failed'))
                            ->send();

                        return;
                    }

                    try {
                        ['plain' => $plain] = app(IngestCredentialIssuer::class)
                            ->issue($user, $tenantId, $record, __('lba.exams.ingest.device_label'));
                    } catch (ExaminationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title($exception->getMessage())
                            ->send();

                        return;
                    }

                    // The secret is shown exactly ONCE; only its hash is stored.
                    Notification::make()
                        ->success()
                        ->title(__('lba.exams.ingest.issued'))
                        ->body($plain)
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
