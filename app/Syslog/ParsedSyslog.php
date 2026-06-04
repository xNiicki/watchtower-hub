<?php

namespace App\Syslog;

use Carbon\CarbonImmutable;

/**
 * Immutable result of parsing a single syslog datagram.
 *
 * @see SyslogParser
 */
readonly class ParsedSyslog
{
    public function __construct(
        public string $host,
        public ?string $facility,
        public string $severity,
        public string $message,
        public CarbonImmutable $loggedAt,
        public ?string $raw = null,
    ) {}
}
