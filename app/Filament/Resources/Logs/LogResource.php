<?php

namespace App\Filament\Resources\Logs;

use App\Filament\Resources\Logs\Pages\ListLogs;
use App\Filament\Resources\Logs\Tables\LogsTable;
use App\Models\SyslogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only view over ingested syslog entries.
 *
 * Syslog rows are written exclusively by the UDP listener via the recorder —
 * never created or edited through Filament. The operator can, however, delete
 * entries for retention/cleanup: per-row, in bulk, or by pruning everything
 * outside a kept timespan (see ListLogs). It mirrors the mobile /api/v1/logs
 * query (host / severity / message search, newest first).
 */
class LogResource extends Resource
{
    protected static ?string $model = SyslogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Logs';

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'log entries';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'message';

    public static function table(Table $table): Table
    {
        return LogsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLogs::route('/'),
        ];
    }
}
