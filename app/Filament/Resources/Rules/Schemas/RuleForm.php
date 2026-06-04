<?php

namespace App\Filament\Resources\Rules\Schemas;

use App\Enums\AlertTier;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('node-cpu-high'),
                        Select::make('condition_type')
                            ->options([
                                'target_down' => 'Target down',
                                'metric_threshold' => 'Metric threshold',
                            ])
                            ->required(),
                        KeyValue::make('params')
                            ->keyLabel('Parameter')
                            ->valueLabel('Value')
                            ->helperText('Condition parameters (e.g. metric, operator, threshold). Stored as JSON.')
                            ->reorderable(),
                        TextInput::make('duration_seconds')
                            ->label('Duration (seconds)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->required(),
                        Select::make('tier')
                            ->options(self::tierOptions())
                            ->default(AlertTier::Warning->value)
                            ->required(),
                        Toggle::make('enabled')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function tierOptions(): array
    {
        $options = [];

        foreach (AlertTier::cases() as $case) {
            $options[$case->value] = ucfirst($case->value);
        }

        return $options;
    }
}
