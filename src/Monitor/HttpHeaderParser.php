<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Monitor;

use function sprintf;

/**
 * Parses "Name: value" header lines from the monitor form.
 */
final class HttpHeaderParser
{
    /**
     * @return array<string, string>
     */
    public static function parseLines(string $text): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name           = trim($name);
            if ($name === '') {
                continue;
            }

            $headers[$name] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function format(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, $value);
        }

        return implode("\n", $lines);
    }
}
