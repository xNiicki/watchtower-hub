<?php

namespace App\Filament\Resources\Apps\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AppForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->required()
                ->alphaDash()
                ->unique(ignoreRecord: true)
                ->helperText('Stable identifier the satellite sends; must match its config app_slug.'),
        ]);
    }
}
