<?php

namespace App\Filament\Resources\Rules\Tables;

use App\Enums\AlertTier;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('condition_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('tier')
                    ->badge()
                    ->color(fn (AlertTier $state): string => match ($state) {
                        AlertTier::Critical => 'danger',
                        AlertTier::Warning => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration (s)')
                    ->numeric()
                    ->sortable(),
                ToggleColumn::make('enabled'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
