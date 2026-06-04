<?php

namespace App\Filament\Resources\Logs\Pages;

use App\Filament\Resources\Logs\LogResource;
use Filament\Resources\Pages\ListRecords;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    // Append-only resource: no create/header actions.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
