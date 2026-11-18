<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeDocumentResource;

class ListKnowledgeDocuments extends ListRecords
{
    protected static string $resource = KnowledgeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('lba.knowledge.document_upload')),
        ];
    }
}
