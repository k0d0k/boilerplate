<?php

namespace Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;

class EditKnowledgeCollection extends EditRecord
{
    protected static string $resource = KnowledgeCollectionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
