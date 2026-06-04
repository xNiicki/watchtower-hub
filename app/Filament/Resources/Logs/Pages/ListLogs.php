<?php

namespace App\Filament\Resources\Logs\Pages;

use App\Filament\Resources\Logs\LogResource;
use App\Models\SyslogEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListLogs extends ListRecords
{
    protected static string $resource = LogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->pruneAction(),
        ];
    }

    /**
     * Retention cleanup: keep a chosen timespan and delete everything outside it.
     *
     * Presets keep a trailing window (delete older than 24h / 7d / 30d); the
     * custom option deletes everything before "from" and after "until".
     */
    private function pruneAction(): Action
    {
        return Action::make('prune')
            ->label('Prune logs')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalHeading('Prune logs')
            ->modalDescription('Delete log entries outside the timespan you want to keep. This cannot be undone.')
            ->modalSubmitActionLabel('Delete entries')
            ->schema([
                Select::make('window')
                    ->label('Keep')
                    ->options([
                        '24h' => 'Last 24 hours',
                        '7d' => 'Last 7 days',
                        '30d' => 'Last 30 days',
                        'custom' => 'Custom range…',
                    ])
                    ->default('7d')
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                DateTimePicker::make('from')
                    ->label('From')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('window') === 'custom'),
                DateTimePicker::make('until')
                    ->label('Until')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('window') === 'custom')
                    ->helperText('Leave either field blank to leave that side of the range open.'),
            ])
            ->action(function (array $data): void {
                [$from, $until] = $this->resolveKeepWindow($data);

                // Nothing to delete if the kept window is unbounded on both ends.
                if ($from === null && $until === null) {
                    Notification::make()
                        ->title('Choose a range')
                        ->body('Set at least one bound so there is something to prune.')
                        ->warning()
                        ->send();

                    return;
                }

                $deleted = SyslogEntry::query()
                    ->where(function ($query) use ($from, $until): void {
                        if ($from !== null) {
                            $query->orWhere('logged_at', '<', $from);
                        }
                        if ($until !== null) {
                            $query->orWhere('logged_at', '>', $until);
                        }
                    })
                    ->delete();

                Notification::make()
                    ->title($deleted > 0 ? "Pruned {$deleted} log entries" : 'No matching entries')
                    ->success()
                    ->send();
            });
    }

    /**
     * Translate the form selection into a [from, until] timespan to KEEP.
     * Either bound may be null, meaning "open-ended on that side".
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveKeepWindow(array $data): array
    {
        return match ($data['window'] ?? null) {
            '24h' => [now()->subDay(), null],
            '7d' => [now()->subDays(7), null],
            '30d' => [now()->subDays(30), null],
            'custom' => [
                ! empty($data['from']) ? Carbon::parse($data['from']) : null,
                ! empty($data['until']) ? Carbon::parse($data['until']) : null,
            ],
            default => [null, null],
        };
    }
}
