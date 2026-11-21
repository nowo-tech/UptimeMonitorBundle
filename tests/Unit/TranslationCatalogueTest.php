<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff_key;
use function array_keys;
use function glob;
use function in_array;
use function is_array;
use function preg_match;
use function str_contains;
use function strlen;

/**
 * Every non-English catalogue has every key of the English one and translates its text: a value identical to English
 * (longer than 15 characters, without placeholders) fails unless it is a brand name, a URL or a technical label.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../src/Resources/translations/';

    /** Values that stay the same in every language (product name, protocol names, example URLs). */
    private const ALLOWED = [
        'app.title',
        'monitor.form.section.tcp',
        'settings.about.product_value',
        'form.monitor.url',
        'form.monitor.host',
        'form.monitor.url_placeholder',
        'form.monitor.proxy_placeholder',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (glob(self::DIR . 'NowoUptimeMonitorBundle.*.yaml') ?: [] as $file) {
            if (preg_match('/\.([a-z]{2})\.yaml$/', $file, $match) === 1 && $match[1] !== 'en') {
                yield $match[1] => [$match[1]];
            }
        }
    }

    #[DataProvider('locales')]
    public function testTheCatalogueHasEveryKeyAndTranslatesIt(string $locale): void
    {
        $en    = self::flat(Yaml::parseFile(self::DIR . 'NowoUptimeMonitorBundle.en.yaml'));
        $other = self::flat(Yaml::parseFile(self::DIR . 'NowoUptimeMonitorBundle.' . $locale . '.yaml'));

        self::assertSame([], array_keys(array_diff_key($en, $other)), 'keys missing in ' . $locale);

        $untranslated = [];
        foreach ($other as $key => $value) {
            if (($en[$key] ?? null) === $value && strlen($value) > 15 && !str_contains($value, '%') && !str_contains($value, '{') && !in_array($key, self::ALLOWED, true)) {
                $untranslated[] = $key;
            }
        }
        self::assertSame([], $untranslated, 'values identical to English in ' . $locale);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flat(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += self::flat($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
