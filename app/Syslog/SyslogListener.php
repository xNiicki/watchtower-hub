<?php

namespace App\Syslog;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Long-running UDP syslog listener built on PHP streams (no sockets extension).
 *
 * Datagrams are read non-blocking, parsed via {@see SyslogParser}, buffered, and
 * flushed to {@see SyslogRecorder} in batches once the buffer reaches a size
 * threshold or a time interval elapses — never one INSERT per message.
 *
 * The class is deliberately decoupled from Laravel's command layer: the loop is
 * driven by an injected predicate ({@see run()}) and the unit-of-work methods
 * ({@see drainOnce()} and {@see flush()}) are public so tests can exercise the
 * ingestion path against a real ephemeral UDP socket without an infinite loop.
 */
class SyslogListener
{
    /**
     * The bound UDP stream socket, or null until {@see bind()} is called.
     *
     * @var resource|null
     */
    private $socket = null;

    /**
     * Buffered parsed datagrams awaiting a flush.
     *
     * @var array<int, ParsedSyslog>
     */
    private array $buffer = [];

    /**
     * Monotonic timestamp (seconds, float) of the last flush. Used to decide
     * whether the time-based flush interval has elapsed.
     */
    private float $lastFlushAt = 0.0;

    public function __construct(
        private readonly SyslogParser $parser,
        private readonly SyslogRecorder $recorder,
        private readonly int $batchSize = 100,
        private readonly float $flushIntervalSeconds = 1.0,
        private readonly int $sleepMicroseconds = 1000,
    ) {}

    /**
     * Open and bind the UDP socket. Binding to port 0 selects an ephemeral port
     * which can then be discovered via {@see boundPort()} (used by tests).
     */
    public function bind(string $host, int $port): void
    {
        $address = "udp://{$host}:{$port}";

        $socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );

        if ($socket === false) {
            throw new RuntimeException("Unable to bind {$address}: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->lastFlushAt = microtime(true);
    }

    /**
     * The local port the socket is bound to. Resolves the ephemeral port chosen
     * by the kernel when binding to port 0.
     */
    public function boundPort(): int
    {
        $name = stream_socket_get_name($this->requireSocket(), false);

        if ($name === false) {
            return 0;
        }

        // $name is "host:port"; the port follows the final colon (IPv6-safe).
        $colon = strrpos($name, ':');

        return $colon === false ? 0 : (int) substr($name, $colon + 1);
    }

    /**
     * Run the receive loop until the predicate returns false. Each iteration
     * drains all pending datagrams, flushes when the buffer is full or the
     * interval elapses, then yields the CPU briefly to avoid a busy-spin.
     *
     * The remaining buffer is flushed on exit so no datagram is lost on
     * graceful shutdown.
     *
     * @param  callable(): bool  $shouldContinue
     */
    public function run(callable $shouldContinue): void
    {
        while ($shouldContinue()) {
            $this->drainOnce();

            if ($this->shouldFlush()) {
                $this->flush();
            }

            usleep($this->sleepMicroseconds);
        }

        $this->flush();
    }

    /**
     * Read every datagram currently available on the socket, derive the source
     * host from the sender address, parse each, and buffer the results.
     *
     * Returns the number of datagrams ingested this call. Non-blocking reads
     * return false when nothing is pending — that is the normal "empty" case,
     * not an error.
     */
    public function drainOnce(): int
    {
        $socket = $this->requireSocket();
        $count = 0;
        $now = CarbonImmutable::now();

        while (true) {
            $peer = '';
            $datagram = @stream_socket_recvfrom($socket, 65535, 0, $peer);

            if ($datagram === false || $datagram === '') {
                break;
            }

            $this->buffer[] = $this->parser->parse(
                $datagram,
                $this->hostFromPeer($peer),
                $now,
            );

            $count++;
        }

        return $count;
    }

    /**
     * Persist the buffered datagrams in a single batch and reset the buffer and
     * flush timer. A no-op when the buffer is empty.
     */
    public function flush(): void
    {
        $this->lastFlushAt = microtime(true);

        if ($this->buffer === []) {
            return;
        }

        $this->recorder->record($this->buffer);
        $this->buffer = [];
    }

    /**
     * Close the underlying socket. Safe to call when already closed.
     */
    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * Number of datagrams currently buffered (not yet flushed).
     */
    public function bufferedCount(): int
    {
        return count($this->buffer);
    }

    /**
     * Whether the buffer should be flushed: it reached the batch size, or the
     * flush interval elapsed while holding at least one entry.
     */
    private function shouldFlush(): bool
    {
        if ($this->buffer === []) {
            return false;
        }

        if (count($this->buffer) >= $this->batchSize) {
            return true;
        }

        return (microtime(true) - $this->lastFlushAt) >= $this->flushIntervalSeconds;
    }

    /**
     * Derive the fallback host from a "ip:port" peer address. The IP is used as
     * the host when the syslog HOSTNAME field is absent.
     */
    private function hostFromPeer(string $peer): ?string
    {
        if ($peer === '') {
            return null;
        }

        // Strip the trailing :port (IPv6-safe — the IP keeps its inner colons).
        $colon = strrpos($peer, ':');

        return $colon === false ? $peer : substr($peer, 0, $colon);
    }

    /**
     * @return resource
     */
    private function requireSocket()
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('Socket is not bound; call bind() first.');
        }

        return $this->socket;
    }
}
