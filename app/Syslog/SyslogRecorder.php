<?php

namespace App\Syslog;

use App\Models\SyslogEntry;
use Carbon\CarbonImmutable;

/**
 * Persists parsed syslog datagrams into the syslog_entries table.
 *
 * Designed for high volume: a single batch insert per call. The C2 UDP listener
 * buffers ParsedSyslog values and flushes them here.
 */
class SyslogRecorder
{
    /**
     * Batch-insert parsed entries. received_at is stamped now (UTC) for the
     * whole batch.
     *
     * @param  array<int, ParsedSyslog>  $entries
     */
    public function record(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $receivedAt = CarbonImmutable::now()->utc()->toDateTimeString();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                'host' => $entry->host,
                'facility' => $entry->facility,
                'severity' => $entry->severity,
                'message' => $entry->message,
                'raw' => $entry->raw,
                'logged_at' => $entry->loggedAt->utc()->toDateTimeString(),
                'received_at' => $receivedAt,
            ];
        }

        SyslogEntry::insert($rows);
    }
}
