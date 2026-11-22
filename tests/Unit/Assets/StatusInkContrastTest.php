<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit\Assets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function file_get_contents;
use function hexdec;
use function max;
use function min;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function round;
use function sprintf;
use function str_contains;
use function substr;

/**
 * 1.37 (UX-047): the status pill inks are their own tokens and meet WCAG 1.4.3 (4.5:1) on the tinted pill backgrounds
 * (tint of the bar colour over the theme surfaces) in light and dark; the bright bar colours alone do not.
 */
final class StatusInkContrastTest extends TestCase
{
    private const BARS     = ['up' => '#22c55e', 'down' => '#ef4444', 'degraded' => '#eab308', 'unknown' => '#64748b'];
    private const SURFACES = [
        'light' => ['#ffffff', '#f1f5f9', '#f8fafc'],
        'dark'  => ['#252830', '#1a1d23', '#15171c', '#12141a', '#1e2229'],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function inks(): iterable
    {
        foreach (['light', 'dark'] as $theme) {
            foreach (['up', 'down', 'degraded', 'unknown'] as $status) {
                yield $theme . ' ' . $status => [$theme, $status];
            }
        }
    }

    #[Test]
    #[DataProvider('inks')]
    public function everyInkMeetsAaOnItsPillBackgrounds(string $theme, string $status): void
    {
        $ink = $this->variable('$uptime-' . $status . '-ink-' . $theme);
        foreach (self::SURFACES[$theme] as $surface) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($ink, $surface), $status . ' ink on the bare surface ' . $surface);
            foreach ([0.2, 0.22, 0.25, 0.28] as $alpha) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($ink, $this->blend(self::BARS[$status], $surface, $alpha)), $status . ' ink on a ' . $alpha . ' tint over ' . $surface);
            }
        }
    }

    #[Test]
    public function theBrightBarGreenAloneFailsOnWhiteWhichIsWhyTheInkIsSeparate(): void
    {
        self::assertLessThan(3.0, $this->contrast(self::BARS['up'], '#ffffff'));
    }

    #[Test]
    public function theCompiledStylesheetsCarryTheInkTokensForBothThemes(): void
    {
        $public = dirname(__DIR__, 3) . '/src/Resources/public/';
        $theme  = (string) file_get_contents($public . 'uptime-theme.css');
        $dash   = (string) file_get_contents($public . 'uptime-dashboard.css');

        foreach (['up', 'down', 'degraded', 'unknown'] as $status) {
            self::assertTrue(str_contains($theme, '--uptime-' . $status . '-ink:' . $this->variable('$uptime-' . $status . '-ink-light')), $status . ' light ink in uptime-theme.css');
            self::assertTrue(str_contains($theme, '--uptime-' . $status . '-ink:' . $this->variable('$uptime-' . $status . '-ink-dark')), $status . ' dark ink in uptime-theme.css');
            self::assertTrue(str_contains($dash, '--uptime-' . $status . '-ink:' . $this->variable('$uptime-' . $status . '-ink-dark')), $status . ' dark default in uptime-dashboard.css');
        }
        // the pills read the ink, not the bar colour
        self::assertMatchesRegularExpression('#\.uptime-status-up\{[^}]*color:var\(--uptime-up-ink#', $dash);
        self::assertMatchesRegularExpression('#\.uptime-uptime-pill-up\{[^}]*color:var\(--uptime-up-ink#', (string) preg_replace('/\s+/', '', $dash));
        self::assertDoesNotMatchRegularExpression('#\.uptime-status-up\{[^}]*;color:var\(--uptime-up[,)]#', $dash);
    }

    private function variable(string $name): string
    {
        $scss = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resources/assets/scss/_variables.scss');
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . ': (#[0-9a-f]{6});/m', $scss, $m), $name);

        return $m[1];
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        return array_map(static fn (string $c): int => (int) hexdec($c), [substr($hex, 1, 2), substr($hex, 3, 2), substr($hex, 5, 2)]);
    }

    private function blend(string $fg, string $bg, float $alpha): string
    {
        $f = $this->rgb($fg);
        $b = $this->rgb($bg);

        return sprintf('#%02x%02x%02x', (int) round($alpha * $f[0] + (1 - $alpha) * $b[0]), (int) round($alpha * $f[1] + (1 - $alpha) * $b[1]), (int) round($alpha * $f[2] + (1 - $alpha) * $b[2]));
    }

    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $this->rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
