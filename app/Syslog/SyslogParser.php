<?php

namespace App\Syslog;

use Carbon\CarbonImmutable;

/**
 * Pure syslog parser for RFC5424 and RFC3164 (BSD) datagrams.
 *
 * Never throws: a malformed or PRI-less line is returned as a best-effort
 * ParsedSyslog so that ingestion never drops a message.
 */
class SyslogParser
{
    /**
     * Severity number → name (RFC5424 §6.2.1).
     *
     * @var array<int, string>
     */
    private const SEVERITIES = [
        0 => 'emerg',
        1 => 'alert',
        2 => 'crit',
        3 => 'err',
        4 => 'warning',
        5 => 'notice',
        6 => 'info',
        7 => 'debug',
    ];

    /**
     * Facility number → name (RFC5424 §6.2.1). Unknown codes fall back to the
     * number rendered as a string.
     *
     * @var array<int, string>
     */
    private const FACILITIES = [
        0 => 'kern',
        1 => 'user',
        2 => 'mail',
        3 => 'daemon',
        4 => 'auth',
        5 => 'syslog',
        6 => 'lpr',
        7 => 'news',
        8 => 'uucp',
        9 => 'cron',
        10 => 'authpriv',
        11 => 'ftp',
        12 => 'ntp',
        13 => 'security',
        14 => 'console',
        15 => 'solaris-cron',
        16 => 'local0',
        17 => 'local1',
        18 => 'local2',
        19 => 'local3',
        20 => 'local4',
        21 => 'local5',
        22 => 'local6',
        23 => 'local7',
    ];

    /**
     * Parse a raw datagram. The result always carries a usable severity, host,
     * message and loggedAt regardless of how malformed the input was.
     */
    public function parse(string $raw, ?string $sourceHost = null, ?CarbonImmutable $now = null): ParsedSyslog
    {
        $now = $now ?? CarbonImmutable::now();
        $trimmed = trim($raw);

        // Extract the PRI value: a run of digits inside leading <...>.
        if (preg_match('/^<(\d{1,3})>(.*)$/s', $trimmed, $m) !== 1) {
            return $this->fallback($trimmed, $raw, $sourceHost, $now);
        }

        $pri = (int) $m[1];
        $remainder = $m[2];

        $facility = $this->facilityName($pri >> 3);
        $severity = $this->severityName($pri & 7);

        // RFC5424 begins with a VERSION digit immediately after the PRI.
        if (preg_match('/^(\d) (.*)$/s', $remainder, $vm) === 1) {
            $parsed = $this->parseRfc5424($vm[2], $now);
            if ($parsed !== null) {
                return new ParsedSyslog(
                    host: $parsed['host'] ?? $sourceHost ?? 'unknown',
                    facility: $facility,
                    severity: $severity,
                    message: $parsed['message'],
                    loggedAt: $parsed['loggedAt'],
                    raw: $raw,
                );
            }
        }

        // Otherwise attempt RFC3164 (BSD) format.
        $parsed = $this->parseRfc3164($remainder, $now);
        if ($parsed !== null) {
            return new ParsedSyslog(
                host: $parsed['host'] ?? $sourceHost ?? 'unknown',
                facility: $facility,
                severity: $severity,
                message: $parsed['message'],
                loggedAt: $parsed['loggedAt'],
                raw: $raw,
            );
        }

        // PRI decoded but body unrecognised: keep the decoded fac/sev, treat the
        // remainder as the message.
        return new ParsedSyslog(
            host: $sourceHost ?? 'unknown',
            facility: $facility,
            severity: $severity,
            message: trim($remainder),
            loggedAt: $now,
            raw: $raw,
        );
    }

    /**
     * RFC5424: VERSION already stripped.
     * TIMESTAMP HOSTNAME APP-NAME PROCID MSGID STRUCTURED-DATA MSG
     *
     * @return array{host: ?string, message: string, loggedAt: CarbonImmutable}|null
     */
    private function parseRfc5424(string $body, CarbonImmutable $now): ?array
    {
        // The five leading header fields (TIMESTAMP HOSTNAME APP-NAME PROCID
        // MSGID) are single tokens with no spaces. Split them off; the remainder
        // is STRUCTURED-DATA followed by an optional MSG.
        $parts = explode(' ', $body, 6);

        if (count($parts) < 6) {
            return null;
        }

        [$timestamp, $hostname, , , , $sdAndMsg] = $parts;

        $loggedAt = $this->parseRfc5424Timestamp($timestamp) ?? $now;

        $message = $this->splitStructuredDataFromMessage($sdAndMsg);

        if ($message === null) {
            // STRUCTURED-DATA was neither '-' nor a bracketed element: the body
            // does not match the RFC5424 shape closely enough.
            return null;
        }

        return [
            'host' => $this->nilable($hostname),
            'message' => $message,
            'loggedAt' => $loggedAt,
        ];
    }

    /**
     * Strip the STRUCTURED-DATA field from the start of "$sdAndMsg" and return
     * the trailing MSG. STRUCTURED-DATA is either the NIL value '-' or one or
     * more "[...]" elements (which may contain spaces). Returns null when the
     * field is not recognisable.
     */
    private function splitStructuredDataFromMessage(string $sdAndMsg): ?string
    {
        if ($sdAndMsg === '-' || str_starts_with($sdAndMsg, '- ')) {
            return ltrim(substr($sdAndMsg, 1));
        }

        if (! str_starts_with($sdAndMsg, '[')) {
            return null;
        }

        // Consume balanced bracketed SD-ELEMENTs. ']' inside a param value is
        // escaped as '\]', so only an unescaped ']' closes an element.
        $length = strlen($sdAndMsg);
        $i = 0;
        while ($i < $length && $sdAndMsg[$i] === '[') {
            $i++;
            while ($i < $length) {
                if ($sdAndMsg[$i] === '\\' && $i + 1 < $length) {
                    $i += 2;

                    continue;
                }
                if ($sdAndMsg[$i] === ']') {
                    $i++;
                    break;
                }
                $i++;
            }
        }

        return ltrim(substr($sdAndMsg, $i));
    }

    /**
     * RFC3164 (BSD): PRI already stripped.
     * TIMESTAMP HOSTNAME TAG: MSG   (TIMESTAMP = "Mmm dd hh:mm:ss", no year)
     *
     * @return array{host: ?string, message: string, loggedAt: CarbonImmutable}|null
     */
    private function parseRfc3164(string $body, CarbonImmutable $now): ?array
    {
        // Timestamp: 3-letter month, day (space-padded), HH:MM:SS.
        $pattern = '/^([A-Z][a-z]{2})\s+(\d{1,2}) (\d{2}:\d{2}:\d{2}) (\S+) (.*)$/s';

        if (preg_match($pattern, $body, $m) !== 1) {
            return null;
        }

        [, $month, $day, $time, $hostname, $rest] = $m;

        $loggedAt = $this->parseRfc3164Timestamp($month, $day, $time, $now) ?? $now;

        return [
            'host' => $this->nilable($hostname),
            'message' => trim($rest),
            'loggedAt' => $loggedAt,
        ];
    }

    private function parseRfc5424Timestamp(string $value): ?CarbonImmutable
    {
        if ($value === '-' || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseRfc3164Timestamp(string $month, string $day, string $time, CarbonImmutable $now): ?CarbonImmutable
    {
        // RFC3164 omits the year; fill it from $now (passed for testability).
        $candidate = sprintf('%s %02d %s %d', $month, (int) $day, $time, $now->year);

        $parsed = CarbonImmutable::createFromFormat('M d H:i:s Y', $candidate);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Build a best-effort result for input that has no decodable PRI.
     */
    private function fallback(string $trimmed, string $raw, ?string $sourceHost, CarbonImmutable $now): ParsedSyslog
    {
        return new ParsedSyslog(
            host: $sourceHost ?? 'unknown',
            facility: null,
            severity: 'info',
            message: $trimmed,
            loggedAt: $now,
            raw: $raw,
        );
    }

    private function severityName(int $code): string
    {
        return self::SEVERITIES[$code] ?? 'unknown';
    }

    private function facilityName(int $code): string
    {
        return self::FACILITIES[$code] ?? (string) $code;
    }

    /**
     * RFC5424 uses '-' as the NIL value for optional header fields.
     */
    private function nilable(string $value): ?string
    {
        return $value === '-' ? null : $value;
    }
}
