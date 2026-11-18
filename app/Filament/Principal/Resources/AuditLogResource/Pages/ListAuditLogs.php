<?php

namespace App\Filament\Principal\Resources\AuditLogResource\Pages;

use App\Filament\Principal\Resources\AuditLogResource;
use App\Filament\Principal\Widgets\SecurityPostureWidget;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SecurityPostureWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
