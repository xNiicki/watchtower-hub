<?php

namespace App\Filament\Resources\AppEvents;

use App\Filament\Resources\AppEvents\Pages\ListAppEvents;
use App\Filament\Resources\AppEvents\Tables\AppEventsTable;
use App\Models\AppEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AppEventResource extends Resource
{
    protected static ?string $model = AppEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static ?string $navigationLabel = 'App Events';

    protected static ?string $modelLabel = 'app event';

    protected static ?string $pluralModelLabel = 'app events';

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return AppEventsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAppEvents::route('/')];
    }
}
