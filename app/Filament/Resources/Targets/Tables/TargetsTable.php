<?php

namespace App\Filament\Resources\Targets\Tables;

use App\Enums\TargetStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class TargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('node')
                    ->placeholder('—')
                    ->toggleable(),
                ToggleColumn::make('enabled'),
                TextColumn::make('check.status')
                    ->label('Status')
                    ->badge()
                    ->placeholder('unknown')
                    ->color(fn (?TargetStatus $state): string => match ($state) {
                        TargetStatus::Up => 'success',
                        TargetStatus::Down => 'danger',
                        TargetStatus::Paused => 'gray',
                        default => 'warning',
                    }),
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
