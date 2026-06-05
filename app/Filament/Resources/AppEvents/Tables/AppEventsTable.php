<?php

namespace App\Filament\Resources\AppEvents\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime('M j H:i:s')->sortable(),
                TextColumn::make('app.name')->label('App')->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'critical' ? 'danger' : 'warning'),
                TextColumn::make('title')->searchable(),
                TextColumn::make('occurrences')->label('×')->sortable(),
                TextColumn::make('message')
                    ->wrap()->limit(140)
                    ->searchable(query: fn (Builder $q, string $s): Builder => $q->where('message', 'ilike', '%'.$s.'%')),
            ])
            ->filters([
                SelectFilter::make('severity')->options(['critical' => 'Critical', 'warning' => 'Warning']),
                SelectFilter::make('type')->options([
                    'exception' => 'Exception',
                    'failed_job' => 'Failed job',
                    'failed_scheduled_task' => 'Failed scheduled task',
                ]),
            ]);
    }
}
