<?php

namespace Tests\Feature;

use App\Models\SyslogEntry;
use App\Syslog\ParsedSyslog;
use App\Syslog\SyslogRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyslogRecorderTest extends TestCase
{
    use RefreshDatabase;

    private SyslogRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new SyslogRecorder;
    }

    public function test_batch_insert_writes_rows_with_correct_columns(): void
    {
        $loggedAt = CarbonImmutable::parse('2026-06-04 10:00:00', 'UTC');

        $entries = [
            new ParsedSyslog('host-a', 'auth', 'crit', 'login failed', $loggedAt, 'raw-a'),
            new ParsedSyslog('host-b', 'daemon', 'info', 'service started', $loggedAt->addMinute(), 'raw-b'),
        ];

        $this->recorder->record($entries);

        $this->assertDatabaseCount('syslog_entries', 2);

        $this->assertDatabaseHas('syslog_entries', [
            'host' => 'host-a',
            'facility' => 'auth',
            'severity' => 'crit',
            'message' => 'login failed',
            'raw' => 'raw-a',
        ]);

        $this->assertDatabaseHas('syslog_entries', [
            'host' => 'host-b',
            'facility' => 'daemon',
            'severity' => 'info',
            'message' => 'service started',
        ]);
    }

    public function test_logged_at_is_preserved_and_received_at_is_set(): void
    {
        $loggedAt = CarbonImmutable::parse('2026-06-04 10:00:00', 'UTC');
        $before = CarbonImmutable::now()->subSecond();

        $this->recorder->record([
            new ParsedSyslog('host-a', null, 'info', 'msg', $loggedAt),
        ]);

        $entry = SyslogEntry::first();

        $this->assertSame('2026-06-04 10:00:00', $entry->logged_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($entry->facility);
        $this->assertTrue($entry->received_at->greaterThanOrEqualTo($before));
    }

    public function test_empty_batch_inserts_nothing(): void
    {
        $this->recorder->record([]);

        $this->assertDatabaseCount('syslog_entries', 0);
    }

    public function test_messages_are_stored_in_plaintext(): void
    {
        $this->recorder->record([
            new ParsedSyslog('host', null, 'info', 'plain text body', CarbonImmutable::now()),
        ]);

        // Raw DB read: the column value must be the literal message, not ciphertext.
        $value = DB::table('syslog_entries')->value('message');

        $this->assertSame('plain text body', $value);
    }

    public function test_ilike_search_returns_matching_entries(): void
    {
        $now = CarbonImmutable::now();

        $this->recorder->record([
            new ParsedSyslog('web-1', 'daemon', 'err', 'database connection refused', $now),
            new ParsedSyslog('web-1', 'daemon', 'info', 'cache warmed successfully', $now),
            new ParsedSyslog('db-1', 'daemon', 'crit', 'DATABASE disk full', $now),
        ]);

        // Proves the message column + trigram index path is usable for substring,
        // case-insensitive search.
        $matches = SyslogEntry::where('message', 'ILIKE', '%database%')
            ->orderBy('id')
            ->pluck('message')
            ->all();

        $this->assertCount(2, $matches);
        $this->assertSame('database connection refused', $matches[0]);
        $this->assertSame('DATABASE disk full', $matches[1]);
    }
}
