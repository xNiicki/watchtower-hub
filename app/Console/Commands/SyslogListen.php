<?php

namespace App\Console\Commands;

use App\Syslog\SyslogListener;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('syslog:listen {--host=0.0.0.0} {--port=514}')]
#[Description('Run the long-running UDP syslog listener (batched ingest, graceful shutdown)')]
class SyslogListen extends Command
{
    /**
     * Set by the SIGTERM/SIGINT handlers to break the receive loop so the
     * listener can flush its buffer and close the socket before exiting.
     */
    private bool $stopped = false;

    public function handle(SyslogListener $listener): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        $listener->bind($host, $port);

        $this->installSignalHandlers();

        $this->line("syslog:listen: listening on udp://{$host}:{$listener->boundPort()}");

        $listener->run(function (): bool {
            pcntl_signal_dispatch();

            return ! $this->stopped;
        });

        $listener->close();

        $this->line('syslog:listen: shutdown complete, buffer flushed');

        return self::SUCCESS;
    }

    /**
     * Install pcntl handlers so SIGTERM (Docker stop) and SIGINT (Ctrl-C)
     * request a graceful stop rather than killing the process mid-batch.
     */
    private function installSignalHandlers(): void
    {
        $handler = function (): void {
            $this->stopped = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}
