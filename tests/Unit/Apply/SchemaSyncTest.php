<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Apply;

use Nowo\UptimeMonitorBundle\Apply\ApplyRefusedException;
use Nowo\UptimeMonitorBundle\Apply\MonitorFileParser;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Tests\Support\MiniJsonSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function file_get_contents;
use function json_decode;
use function sort;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * The shipped `apply-monitors.v1.json` (what DEVOPS lints the file with) and {@see MonitorFileParser} (what the command
 * enforces) agree: the contract's example passes both, every fixture the schema refuses is refused by the parser, and
 * the constants match. The parser is stricter on purpose (unique keys, warn ≤ critical), which a schema
 * cannot express: those fixtures pass the schema and fail the parser.
 */
#[CoversClass(MonitorFileParser::class)]
final class SchemaSyncTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../src/Resources/schema/apply-monitors.v1.json';

    private function schema(): stdClass
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $schema);

        return $schema;
    }

    private function parserAccepts(string $json): bool
    {
        try {
            (new MonitorFileParser())->parse($json);

            return true;
        } catch (ApplyRefusedException) {
            return false;
        }
    }

    private function schemaAccepts(string $json): bool
    {
        return (new MiniJsonSchema($this->schema()))->isValid(json_decode($json, false, 64, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function with(array $entry, string $tenant = MonitorFileParserTest::TENANT): string
    {
        return MonitorFileParserTest::file([$entry], $tenant);
    }

    /**
     * The example of the contract (§3.3), placeholders only.
     */
    public static function example(): string
    {
        return <<<'JSON'
{
  "$schema": "https://github.com/nowo-tech/UptimeMonitorBundle/schema/apply-monitors.v1.json",
  "version": 1,
  "tenants": {
    "00000000-0000-4000-8000-000000000000": {
      "monitors": [
        {
          "key": "little-vps-1.resources",
          "type": "push",
          "name": "little-vps-1 — resources",
          "project": "infra",
          "description": "Disk, memory, CPU and load pushed by uptime-push-agent every minute.",
          "url_key": "0123456789abcdef0123456789abcdef",
          "interval_seconds": 60,
          "grace_seconds": 120,
          "retries": 1,
          "resend_notification_after_down": 0,
          "thresholds": {
            "disk":   { "warn": 85, "critical": 95 },
            "memory": { "warn": 90, "critical": 97 },
            "load":   { "warn": 1.5, "critical": 3.0 },
            "swap":   { "warn": null, "critical": null }
          }
        }
      ]
    }
  }
}
JSON;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusedByBoth(): iterable
    {
        $entry = MonitorFileParserTest::entry();
        yield 'not an object' => ['[]'];
        yield 'no version' => ['{"tenants": {}}'];
        yield 'version 2' => ['{"version": 2, "tenants": {"' . MonitorFileParserTest::TENANT . '": {"monitors": []}}}'];
        yield 'unknown root key' => ['{"version": 1, "x": 1, "tenants": {"' . MonitorFileParserTest::TENANT . '": {"monitors": []}}}'];
        yield 'no tenants' => ['{"version": 1, "tenants": {}}'];
        yield 'uppercase tenant' => [self::with($entry, '00000000-0000-4000-8000-00000000000A')];
        yield 'non-uuid tenant' => [self::with($entry, 'main')];
        yield 'unknown tenant key' => ['{"version": 1, "tenants": {"' . MonitorFileParserTest::TENANT . '": {"monitors": [], "x": 1}}}'];
        yield 'no monitors' => ['{"version": 1, "tenants": {"' . MonitorFileParserTest::TENANT . '": {}}}'];
        yield 'unknown entry key' => [self::with(['token' => 'x'] + $entry)];
        yield 'http type' => [self::with(['type' => 'http'] + $entry)];
        yield 'bad key' => [self::with(['key' => '-starts-with-dash'] + $entry)];
        yield 'long key' => [self::with(['key' => str_repeat('a', 65)] + $entry)];
        yield 'uppercase url key' => [self::with(['url_key' => '0123456789ABCDEF0123456789abcdef'] + $entry)];
        yield 'empty name' => [self::with(['name' => ''] + $entry)];
        yield 'long name' => [self::with(['name' => str_repeat('n', 256)] + $entry)];
        yield 'long project' => [self::with(['project' => str_repeat('p', 129)] + $entry)];
        yield 'long description' => [self::with(['description' => str_repeat('d', 2001)] + $entry)];
        yield 'interval 29' => [self::with(['interval_seconds' => 29] + $entry)];
        yield 'interval as string' => [self::with(['interval_seconds' => '60'] + $entry)];
        yield 'interval over a year' => [self::with(['interval_seconds' => 31_536_001] + $entry)];
        yield 'grace over a day' => [self::with(['grace_seconds' => 86_401] + $entry)];
        yield 'retries 101' => [self::with(['retries' => 101] + $entry)];
        yield 'resend 10001' => [self::with(['resend_notification_after_down' => 10_001] + $entry)];
        yield 'paused as string' => [self::with(['paused' => 'yes'] + $entry)];
        yield 'unknown metric' => [self::with(['thresholds' => ['gpu' => ['warn' => 1, 'critical' => 2]]] + $entry)];
        yield 'limit without critical' => [self::with(['thresholds' => ['disk' => ['warn' => 1]]] + $entry)];
        yield 'negative limit' => [self::with(['thresholds' => ['load' => ['warn' => -1, 'critical' => 2]]] + $entry)];
        yield 'long $schema' => ['{"$schema": "' . str_repeat('s', 513) . '", "version": 1, "tenants": {"' . MonitorFileParserTest::TENANT . '": {"monitors": []}}}'];
        yield 'percent over 100' => [self::with(['thresholds' => ['disk' => ['warn' => 90, 'critical' => 101]]] + $entry)];
        yield 'load over 10000' => [self::with(['thresholds' => ['load' => ['warn' => 1, 'critical' => 10_001]]] + $entry)];
        yield 'missing url key' => ['{"version": 1, "tenants": {"' . MonitorFileParserTest::TENANT . '": {"monitors": [{"key": "a", "type": "push", "name": "a", "interval_seconds": 60}]}}}'];
    }

    public function testTheContractExamplePassesBoth(): void
    {
        self::assertTrue($this->schemaAccepts(self::example()));
        self::assertTrue($this->parserAccepts(self::example()));
    }

    #[DataProvider('refusedByBoth')]
    public function testWhatTheSchemaRefusesTheParserRefuses(string $json): void
    {
        self::assertFalse($this->schemaAccepts($json), 'schema');
        self::assertFalse($this->parserAccepts($json), 'parser');
    }

    public function testTheParserIsStricterWhereASchemaCannotSay(): void
    {
        $entry = MonitorFileParserTest::entry();
        $twice = MonitorFileParserTest::file([$entry, ['url_key' => 'fedcba9876543210fedcba9876543210'] + $entry]);
        $cases = [
            'duplicate key'       => $twice,
            'warn above critical' => self::with(['thresholds' => ['memory' => ['warn' => 95, 'critical' => 90]]] + $entry),
        ];
        foreach ($cases as $name => $json) {
            self::assertTrue($this->schemaAccepts($json), $name);
            self::assertFalse($this->parserAccepts($json), $name);
        }
    }

    public function testNullOptionalFieldsPassBoth(): void
    {
        $json = self::with(['project' => null, 'description' => null, 'grace_seconds' => null, 'retries' => null, 'resend_notification_after_down' => null, 'paused' => null, 'thresholds' => null] + MonitorFileParserTest::entry());

        self::assertTrue($this->schemaAccepts($json));
        self::assertTrue($this->parserAccepts($json));
    }

    public function testTheLimitsAndPatternsAreTheSame(): void
    {
        $schema  = $this->schema();
        $monitor = $schema->{'$defs'}->pushMonitor;
        self::assertSame(MonitorFileParser::MAX_TENANTS, $schema->properties->tenants->maxProperties);
        self::assertSame(MonitorFileParser::MAX_MONITORS, $schema->{'$defs'}->tenant->properties->monitors->maxItems);
        self::assertSame(MonitorFileParser::TENANT_PATTERN, '/' . $schema->properties->tenants->propertyNames->pattern . '/');
        self::assertSame(MonitorFileParser::KEY_PATTERN, '/' . $monitor->properties->key->pattern . '/');
        self::assertSame(MonitorFileParser::URL_KEY_PATTERN, '/' . $monitor->properties->url_key->pattern . '/');

        $metrics = array_keys((array) $monitor->properties->thresholds->properties);
        $known   = array_keys(PushThresholds::DEFAULTS);
        sort($metrics);
        sort($known);
        self::assertSame($known, $metrics);
        self::assertSame(['key', 'type', 'name', 'url_key', 'interval_seconds'], $monitor->required);
        self::assertSame('push', $monitor->properties->type->const);
        self::assertSame(MonitorFileParser::MAX_SCHEMA_LENGTH, $schema->properties->{'$schema'}->maxLength);
        self::assertSame(100, $schema->{'$defs'}->percentLimit->properties->warn->maximum);
        self::assertSame(10_000, $schema->{'$defs'}->limit->properties->critical->maximum);
    }
}
