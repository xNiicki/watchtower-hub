<?php

namespace Tests\Unit;

use App\Syslog\ParsedSyslog;
use App\Syslog\SyslogParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SyslogParserTest extends TestCase
{
    private SyslogParser $parser;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SyslogParser;
        $this->now = CarbonImmutable::parse('2026-06-04 12:00:00', 'UTC');
    }

    public function test_parses_rfc5424_message(): void
    {
        $raw = '<34>1 2003-10-11T22:14:15.003Z mymachine.example.com su - ID47 - BOM\'su root\' failed for lonvick on /dev/pts/8';

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame('mymachine.example.com', $parsed->host);
        $this->assertSame('auth', $parsed->facility); // PRI 34 => facility 4 (auth)
        $this->assertSame('crit', $parsed->severity);  // PRI 34 => severity 2 (crit)
        $this->assertStringContainsString('failed for lonvick', $parsed->message);
        $this->assertSame('2003-10-11 22:14:15', $parsed->loggedAt->format('Y-m-d H:i:s'));
        $this->assertSame($raw, $parsed->raw);
    }

    public function test_parses_rfc5424_with_structured_data_and_message(): void
    {
        $raw = '<165>1 2003-10-11T22:14:15.003Z mymachine.example.com evntslog - ID47 [exampleSDID@32473 iut="3"] An application event log entry';

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame('mymachine.example.com', $parsed->host);
        $this->assertSame('local4', $parsed->facility); // 165 >> 3 = 20
        $this->assertSame('notice', $parsed->severity);  // 165 & 7 = 5
        $this->assertSame('An application event log entry', $parsed->message);
    }

    public function test_parses_rfc5424_fractional_and_offset_timestamp(): void
    {
        $raw = '<13>1 2026-01-15T08:30:00.123456+02:00 host1 app - - - hello';

        $parsed = $this->parser->parse($raw, null, $this->now);

        // +02:00 => 06:30 UTC
        $this->assertSame('2026-01-15 06:30:00', $parsed->loggedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('hello', $parsed->message);
    }

    public function test_parses_rfc5424_nil_hostname_falls_back_to_source_host(): void
    {
        $raw = '<13>1 2026-01-15T08:30:00Z - app - - - body';

        $parsed = $this->parser->parse($raw, '10.0.0.5', $this->now);

        $this->assertSame('10.0.0.5', $parsed->host);
    }

    public function test_parses_rfc3164_message_and_fills_year_from_now(): void
    {
        $raw = '<13>Oct 11 22:14:15 mymachine su: \'su root\' failed for lonvick';

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame('mymachine', $parsed->host);
        $this->assertSame('user', $parsed->facility);   // 13 >> 3 = 1 (user)
        $this->assertSame('notice', $parsed->severity);  // 13 & 7 = 5 (notice)
        $this->assertStringContainsString('su:', $parsed->message);
        // Year filled from $now (2026).
        $this->assertSame('2026-10-11 22:14:15', $parsed->loggedAt->format('Y-m-d H:i:s'));
    }

    public function test_parses_rfc3164_with_space_padded_day(): void
    {
        $raw = '<34>Oct  1 09:05:00 host kernel: out of memory';

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame('host', $parsed->host);
        $this->assertSame('2026-10-01 09:05:00', $parsed->loggedAt->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('out of memory', $parsed->message);
    }

    #[DataProvider('priProvider')]
    public function test_pri_decodes_to_facility_and_severity(int $pri, string $facility, string $severity): void
    {
        $raw = "<{$pri}>1 2026-01-15T08:30:00Z host app - - - msg";

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame($facility, $parsed->facility, "facility for PRI {$pri}");
        $this->assertSame($severity, $parsed->severity, "severity for PRI {$pri}");
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function priProvider(): array
    {
        return [
            'kern emerg' => [0, 'kern', 'emerg'],
            'user notice' => [13, 'user', 'notice'],
            'auth crit' => [34, 'auth', 'crit'],
            'local4 notice' => [165, 'local4', 'notice'],
            'daemon debug' => [31, 'daemon', 'debug'], // 31>>3=3 daemon, 31&7=7 debug
            'cron info' => [78, 'cron', 'info'],        // 78>>3=9 cron, 78&7=6 info
        ];
    }

    public function test_unknown_facility_number_falls_back_to_numeric_string(): void
    {
        // facility 24 has no name in the map => "24".
        $pri = 24 << 3; // severity 0 (emerg)
        $raw = "<{$pri}>1 2026-01-15T08:30:00Z host app - - - msg";

        $parsed = $this->parser->parse($raw, null, $this->now);

        $this->assertSame('24', $parsed->facility);
    }

    public function test_malformed_no_pri_never_throws_and_keeps_raw(): void
    {
        $raw = 'this is not a syslog line at all';

        $parsed = $this->parser->parse($raw, 'sender.local', $this->now);

        $this->assertInstanceOf(ParsedSyslog::class, $parsed);
        $this->assertSame('sender.local', $parsed->host);
        $this->assertNull($parsed->facility);
        $this->assertSame('info', $parsed->severity);
        $this->assertSame('this is not a syslog line at all', $parsed->message);
        $this->assertSame($this->now->format('Y-m-d H:i:s'), $parsed->loggedAt->format('Y-m-d H:i:s'));
        $this->assertSame($raw, $parsed->raw);
    }

    public function test_no_source_host_falls_back_to_unknown(): void
    {
        $parsed = $this->parser->parse('garbage', null, $this->now);

        $this->assertSame('unknown', $parsed->host);
    }

    public function test_empty_string_is_handled(): void
    {
        $parsed = $this->parser->parse('', 'sender', $this->now);

        $this->assertSame('sender', $parsed->host);
        $this->assertSame('', $parsed->message);
        $this->assertSame('info', $parsed->severity);
        $this->assertEquals($this->now->getTimestamp(), $parsed->loggedAt->getTimestamp());
    }

    public function test_pri_only_with_unrecognised_body_keeps_decoded_fields(): void
    {
        // Valid PRI but a body that is neither RFC5424 nor RFC3164.
        $raw = '<34>just some freeform text';

        $parsed = $this->parser->parse($raw, 'src', $this->now);

        $this->assertSame('auth', $parsed->facility);
        $this->assertSame('crit', $parsed->severity);
        $this->assertSame('src', $parsed->host);
        $this->assertSame('just some freeform text', $parsed->message);
    }
}
