<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;

class ViewKnowledgeDocument extends ViewRecord
{
    protected static string $resource = KnowledgeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label(__('lba.knowledge.document_open'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => route('lba.knowledge.documents.download', ['document' => $this->getRecord()->getKey()]))
                ->openUrlInNewTab()
                ->visible(fn (KnowledgeDocument $record): bool => $record->isPublished()),
            Action::make('publish')
                ->label(__('lba.knowledge.action_publish'))
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (KnowledgeDocument $record): bool => KnowledgeDocumentResource::canView($record)
                    && $record->status === KnowledgeDocument::STATUS_REVIEW)
                ->action(function (KnowledgeDocument $record): void {
                    $actor = auth()->user();

                    if ($actor instanceof User) {
                        app(KnowledgeDocumentManager::class)->publish($record, $actor);
                    }
                }),
        ];
    }
}
