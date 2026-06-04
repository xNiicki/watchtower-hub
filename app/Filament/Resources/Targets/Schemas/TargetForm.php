<?php

namespace App\Filament\Resources\Targets\Schemas;

use App\Enums\TargetType;
use App\Models\Target;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('type')
                            ->options(self::typeOptions())
                            // Only service targets can be created/retyped from the UI; infra
                            // types are auto-discovered, so the type is locked once it exists.
                            ->default(TargetType::Service->value)
                            ->required()
                            ->disabled(fn (?Target $record): bool => $record !== null)
                            ->dehydrated()
                            ->live(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            // Infra targets keep their discovered name read-only.
                            ->disabled(fn (?Target $record): bool => $record !== null && $record->type !== TargetType::Service),
                        Toggle::make('enabled')
                            ->default(true),
                    ]),
                Section::make('Service check')
                    ->description('HTTP(S) endpoint polled for this service.')
                    ->visible(fn (Get $get): bool => $get('type') === TargetType::Service->value)
                    ->schema([
                        TextInput::make('check_config.url')
                            ->label('URL')
                            ->url()
                            ->required(fn (Get $get): bool => $get('type') === TargetType::Service->value)
                            ->placeholder('https://service.example.com/health'),
                        TextInput::make('check_config.timeout_ms')
                            ->label('Timeout (ms)')
                            ->numeric()
                            ->default(5000)
                            ->minValue(1),
                        Toggle::make('check_config.verify_tls')
                            ->label('Verify TLS')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];

        foreach (TargetType::cases() as $case) {
            $options[$case->value] = ucfirst($case->value);
        }

        return $options;
    }
}
