<?php

namespace Tests\Feature;

use App\Models\SyslogEntry;
use App\Syslog\SyslogListener;
use App\Syslog\SyslogParser;
use App\Syslog\SyslogRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyslogListenerTest extends TestCase
{
    use RefreshDatabase;

    private function makeListener(int $batchSize = 100): SyslogListener
    {
        $listener = new SyslogListener(
            new SyslogParser,
            new SyslogRecorder,
            batchSize: $batchSize,
        );

        $listener->bind('127.0.0.1', 0);

        return $listener;
    }

    /**
     * Send a UDP datagram to the listener's bound ephemeral port.
     */
    private function send(int $port, string $payload): void
    {
        $client = stream_socket_client("udp://127.0.0.1:{$port}", $errno, $errstr);

        $this->assertIsResource($client, "client connect failed: {$errstr} ({$errno})");

        fwrite($client, $payload);
        fclose($client);
    }

    /**
     * Give the kernel a moment to deliver datagrams to the socket buffer, then
     * drain. UDP delivery on loopback is effectively immediate, but a short
     * retry loop keeps the test deterministic without a fixed sleep.
     */
    private function drainAtLeast(SyslogListener $listener, int $expected): int
    {
        $total = 0;

        for ($attempt = 0; $attempt < 100 && $total < $expected; $attempt++) {
            $total += $listener->drainOnce();

            if ($total < $expected) {
                usleep(1000);
            }
        }

        return $total;
    }

    public function test_receives_and_parses_real_datagrams(): void
    {
        $listener = $this->makeListener();
        $port = $listener->boundPort();

        $this->assertGreaterThan(0, $port);

        // RFC5424: PRI 13 = facility 1 (user), severity 5 (notice). Host present.
        $this->send($port, '<13>1 2026-06-04T10:00:00Z web-1 app 100 ID47 - first message');
        // RFC3164 (BSD): PRI 11 = facility 1 (user), severity 3 (err). Host present.
        // The parser keeps the TAG ("sshd:") as part of the message body.
        $this->send($port, '<11>Jun  4 10:00:00 db-1 sshd: second message');

        $this->assertSame(2, $this->drainAtLeast($listener, 2));

        $listener->flush();
        $listener->close();

        $this->assertDatabaseCount('syslog_entries', 2);

        $this->assertDatabaseHas('syslog_entries', [
            'host' => 'web-1',
            'severity' => 'notice',
            'facility' => 'user',
            'message' => 'first message',
        ]);

        $this->assertDatabaseHas('syslog_entries', [
            'host' => 'db-1',
            'severity' => 'err',
            'facility' => 'user',
            'message' => 'sshd: second message',
        ]);
    }

    public function test_malformed_datagram_is_stored_raw_without_throwing(): void
    {
        $listener = $this->makeListener();
        $port = $listener->boundPort();

        $this->send($port, 'this is not a syslog line at all');

        $this->assertSame(1, $this->drainAtLeast($listener, 1));

        $listener->flush();
        $listener->close();

        $this->assertDatabaseCount('syslog_entries', 1);

        $entry = SyslogEntry::first();

        // Parser never throws: malformed input falls back to severity "info"
        // and the raw datagram is preserved verbatim.
        $this->assertSame('info', $entry->severity);
        $this->assertSame('this is not a syslog line at all', $entry->raw);
        $this->assertSame('this is not a syslog line at all', $entry->message);
    }

    public function test_source_host_falls_back_to_sender_ip_when_hostname_absent(): void
    {
        $listener = $this->makeListener();
        $port = $listener->boundPort();

        // RFC5424 with NIL ('-') HOSTNAME: the sender IP must be used instead.
        $this->send($port, '<13>1 2026-06-04T10:00:00Z - app 100 ID47 - hostless message');

        $this->assertSame(1, $this->drainAtLeast($listener, 1));

        $listener->flush();
        $listener->close();

        $this->assertDatabaseHas('syslog_entries', [
            'host' => '127.0.0.1',
            'message' => 'hostless message',
        ]);
    }

    public function test_drain_buffers_and_flush_writes_in_a_single_batch_insert(): void
    {
        $listener = $this->makeListener(batchSize: 1000);
        $port = $listener->boundPort();

        $count = 25;
        for ($i = 0; $i < $count; $i++) {
            $this->send($port, "<13>1 2026-06-04T10:00:00Z web-1 app 100 ID47 - message {$i}");
        }

        $this->assertSame($count, $this->drainAtLeast($listener, $count));

        // Nothing is persisted until flush — drain only buffers.
        $this->assertDatabaseCount('syslog_entries', 0);
        $this->assertSame($count, $listener->bufferedCount());

        DB::enableQueryLog();
        $listener->flush();
        $inserts = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'insert'))
            ->count();
        DB::disableQueryLog();

        $listener->close();

        $this->assertDatabaseCount('syslog_entries', $count);
        $this->assertSame(1, $inserts, 'the whole buffer must be written in one INSERT');
        $this->assertSame(0, $listener->bufferedCount());
    }

    public function test_run_loop_drains_then_flushes_on_stop(): void
    {
        $listener = $this->makeListener();
        $port = $listener->boundPort();

        $this->send($port, '<13>1 2026-06-04T10:00:00Z web-1 app 100 ID47 - loop message');

        // Wait until the datagram is queued so the single loop iteration sees it.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $pending = @stream_socket_recvfrom($this->reflectSocket($listener), 65535, STREAM_PEEK);
            if ($pending !== false && $pending !== '') {
                break;
            }
            usleep(1000);
        }

        // Predicate returns true once, then false: one drain + final flush.
        $iterations = 0;
        $listener->run(function () use (&$iterations): bool {
            return $iterations++ < 1;
        });

        $listener->close();

        $this->assertDatabaseCount('syslog_entries', 1);
        $this->assertDatabaseHas('syslog_entries', ['message' => 'loop message']);
    }

    /**
     * @return resource
     */
    private function reflectSocket(SyslogListener $listener)
    {
        $property = new \ReflectionProperty(SyslogListener::class, 'socket');

        return $property->getValue($listener);
    }
}
