<?php

namespace App\Filament\Resources\Logs\Tables;

use App\Models\SyslogEntry;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LogsTable
{
    /**
     * Syslog severities, most → least urgent. Drives both the severity filter
     * options and the badge ordering.
     */
    private const SEVERITIES = [
        'emerg' => 'Emergency',
        'alert' => 'Alert',
        'crit' => 'Critical',
        'err' => 'Error',
        'warning' => 'Warning',
        'notice' => 'Notice',
        'info' => 'Info',
        'debug' => 'Debug',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('logged_at', 'desc')
            // Live tail: the listener is appending rows continuously.
            ->poll('15s')
            ->columns([
                TextColumn::make('logged_at')
                    ->label('Time')
                    ->dateTime('M j H:i:s')
                    ->sortable(),
                TextColumn::make('host')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => self::severityColor($state))
                    ->formatStateUsing(fn (string $state): string => self::SEVERITIES[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('facility')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('message')
                    ->wrap()
                    ->limit(160)
                    ->tooltip(fn (TextColumn $column): ?string => strlen((string) $column->getState()) > 160 ? $column->getState() : null)
                    // Postgres-aware, case-insensitive — same as the mobile API (trigram index).
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('message', 'ilike', '%'.$search.'%')),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->dateTime('M j H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options(self::SEVERITIES),
                SelectFilter::make('host')
                    ->options(fn (): array => SyslogEntry::query()
                        ->distinct()
                        ->orderBy('host')
                        ->pluck('host', 'host')
                        ->all())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading('Log entry')
                    ->schema([
                        TextEntry::make('logged_at')->label('Logged at')->dateTime(),
                        TextEntry::make('received_at')->label('Received at')->dateTime(),
                        TextEntry::make('host'),
                        TextEntry::make('facility')->placeholder('—'),
                        TextEntry::make('severity')
                            ->badge()
                            ->color(fn (string $state): string => self::severityColor($state))
                            ->formatStateUsing(fn (string $state): string => self::SEVERITIES[$state] ?? $state),
                        TextEntry::make('message')->columnSpanFull(),
                        TextEntry::make('raw')
                            ->label('Raw datagram')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Map a syslog severity name to a Filament badge color.
     */
    public static function severityColor(string $severity): string
    {
        return match ($severity) {
            'emerg', 'alert', 'crit', 'err' => 'danger',
            'warning' => 'warning',
            'notice' => 'info',
            default => 'gray',
        };
    }
}
