<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;

class ListKnowledgeCollections extends ListRecords
{
    protected static string $resource = KnowledgeCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('lba.knowledge.collection_create')),
        ];
    }
}
