<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist, and a value identical to
 * the English one (any length, ignoring values without letters once placeholders are removed) must be listed in
 * ALLOWED_IDENTICAL for that locale. A non-es value identical to the Spanish one (longer than 12 characters, not English)
 * must be listed in ALLOWED_SAME_AS_SPANISH. An allow-list entry that no longer applies is reported as stale.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../src/Resources/translations';

    /**
     * Keys that are correctly identical to English in a locale (brands, product and technical names, codes, loanwords
     * and cognates), per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_IDENTICAL = [
        'de' => [
            'app.title',
            'dashboard.live',
            'dashboard.table.monitor',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.name_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.tags',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.entry_page.dashboard',
            'form.settings.heartbeat_bar.normal',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.form.section.dns',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.push.disk.inodes',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.range_1',
            'monitor.push.range_24',
            'monitor.push.range_6',
            'monitor.push.token',
            'monitor.show.col.code',
            'monitor.show.col.status',
            'monitor.show.details',
            'monitor.show.seconds',
            'nav.dashboard',
            'settings.about.product_value',
            'settings.nav.tags',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'settings.section.tags',
            'settings.tags.list',
            'status_page.title_suffix',
            'summary.title',
            'tenant.col.name',
            'tenant.col.slug',
        ],
        'es' => [
            'app.title',
            'dashboard.table.monitor',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.heartbeat_bar.normal',
            'form.settings.tag_color',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.form.section.dns',
            'monitor.form.section.general',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.range_1',
            'monitor.push.range_24',
            'monitor.push.range_6',
            'monitor.push.token',
            'monitor.show.seconds',
            'settings.about.product_value',
            'settings.nav.general',
            'settings.notifications.no',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'settings.section.general',
            'tenant.col.slug',
        ],
        'fr' => [
            'app.title',
            'dashboard.table.actions',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.tags',
            'form.monitor.tags_placeholder',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.heartbeat_bar.normal',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.changes.col.action',
            'monitor.changes.source.app',
            'monitor.form.section.dns',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.origin.label',
            'monitor.push.disk.inodes',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.range_1',
            'monitor.push.range_24',
            'monitor.push.range_6',
            'monitor.show.col.code',
            'monitor.show.col.message',
            'monitor.show.seconds',
            'monitor.show.type',
            'settings.about.product_value',
            'settings.nav.tags',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'settings.section.tags',
            'settings.tags.list',
            'status_page.services_heading',
            'tenant.col.slug',
        ],
        'it' => [
            'app.title',
            'dashboard.live',
            'dashboard.table.monitor',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.entry_page.dashboard',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.form.section.dns',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.origin.file_badge_short',
            'monitor.push.disk.mount',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.range_1',
            'monitor.push.range_24',
            'monitor.push.range_6',
            'monitor.push.token',
            'monitor.show.seconds',
            'nav.dashboard',
            'settings.about.product_value',
            'settings.notifications.no',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'tenant.col.slug',
        ],
        'nl' => [
            'app.title',
            'dashboard.live',
            'dashboard.table.monitor',
            'dashboard.table.title',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.name_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.tags',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.entry_page.dashboard',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.form.section.dns',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.push.disk.inodes',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.metric.uptime',
            'monitor.push.token',
            'monitor.show.col.code',
            'monitor.show.col.status',
            'monitor.show.details',
            'monitor.show.project',
            'monitor.show.seconds',
            'monitor.show.type',
            'nav.dashboard',
            'settings.about.product_value',
            'settings.nav.tags',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'settings.section.tags',
            'settings.tags.list',
            'status_page.title_suffix',
            'summary.title',
            'tenant.col.slug',
        ],
        'pt' => [
            'app.title',
            'dashboard.table.monitor',
            'dashboard.title',
            'form.monitor.auth.basic',
            'form.monitor.body_encoding.json',
            'form.monitor.body_encoding.xml',
            'form.monitor.host',
            'form.monitor.host_placeholder',
            'form.monitor.hostname_placeholder',
            'form.monitor.keyword_placeholder',
            'form.monitor.proxy_placeholder',
            'form.monitor.type.dns',
            'form.monitor.type.http',
            'form.monitor.type.https',
            'form.monitor.type.ping',
            'form.monitor.type.tcp',
            'form.monitor.unit.seconds',
            'form.monitor.url',
            'form.monitor.url_placeholder',
            'form.settings.heartbeat_bar.normal',
            'form.settings.theme.auto',
            'form.settings.ui_framework.bootstrap',
            'form.settings.ui_framework.tailwind',
            'form.tenant.slug',
            'monitor.form.section.dns',
            'monitor.form.section.http',
            'monitor.form.section.push',
            'monitor.form.section.tcp',
            'monitor.push.disk.inodes',
            'monitor.push.metric.cpu',
            'monitor.push.metric.swap',
            'monitor.push.range_1',
            'monitor.push.range_24',
            'monitor.push.range_6',
            'monitor.push.token',
            'monitor.show.seconds',
            'settings.about.product_value',
            'settings.notifications.slack',
            'settings.notifications.webhook',
            'tenant.col.slug',
        ],
    ];

    /**
     * Keys whose non-es value legitimately equals the Spanish one (longer than 12 characters, different from English):
     * words and phrases that are genuinely the same in Spanish and the locale, per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_SAME_AS_SPANISH = [
        'it' => [
            'settings.nav.reverse_proxy',
            'settings.section.reverse_proxy',
        ],
        'pt' => [
            'dashboard.filtered_by_monitor',
            'dashboard.status_page',
            'flash.monitor.deleted',
            'flash.settings.tag_deleted',
            'flash.settings.tag_updated',
            'form.monitor.dns_expected',
            'form.monitor.parent',
            'form.monitor.push_threshold.cpu_critical',
            'form.monitor.push_threshold.cpu_warn',
            'form.monitor.push_threshold.disk_critical',
            'form.monitor.push_threshold.disk_warn',
            'form.monitor.push_threshold.load_critical',
            'form.monitor.push_threshold.load_warn',
            'form.monitor.push_threshold.swap_critical',
            'form.monitor.push_threshold.swap_warn',
            'form.monitor.type',
            'form.monitor.type.ssl',
            'form.settings.base_url',
            'form.settings.entry_page',
            'form.settings.entry_page.status',
            'form.settings.status_page_visibility',
            'form.settings.status_page_visibility.private',
            'form.settings.ui_framework',
            'monitor.edit',
            'monitor.form.section.ssl',
            'monitor.push.chart_disks',
            'monitor.push.chart_load',
            'monitor.push.metric.load',
            'monitor.show.interval',
            'monitor.show.sort_by',
            'settings.backup.export_button',
            'settings.nav.reverse_proxy',
            'settings.nav.status_page',
            'settings.notifications.cooldown_seconds',
            'settings.section.reverse_proxy',
            'settings.section.status_page',
            'settings.tags.delete_confirm_title',
            'summary.public_page',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2}(?:_[A-Z]{2})?)\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_IDENTICAL[$locale] ?? [];
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || in_array($key, $allowed, true)) {
                continue;
            }

            if (preg_match('/[^\W\d_]/u', (string) preg_replace('/%[^%\s]+%|\{[^}]+\}/', '', $value)) === 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonSpanishCatalogues(): iterable
    {
        foreach (self::catalogues() as $name => [$domain, $locale]) {
            if ($locale !== 'es') {
                yield $name => [$domain, $locale];
            }
        }
    }

    #[DataProvider('nonSpanishCatalogues')]
    public function testNoSpanishLeftInOtherLocales(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $spanish = $this->load($domain, 'es');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_SAME_AS_SPANISH[$locale] ?? [];
        $leaks   = [];

        foreach ($spanish as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || $value === ($english[$key] ?? null) || mb_strlen($value) <= 12 || in_array($key, $allowed, true)) {
                continue;
            }

            $leaks[] = $key;
        }

        self::assertSame([], $leaks, sprintf('Values identical to Spanish in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_IDENTICAL as $locale => $keys) {
            foreach ($keys as $key) {
                if (!isset($english[$key]) || ($locales[$locale][$key] ?? null) !== $english[$key]) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Allow-list entries that no longer exist or are no longer identical to English');
    }

    public function testSpanishAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $spanish = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $spanish += $this->load($domain, 'es');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_SAME_AS_SPANISH as $locale => $keys) {
            foreach ($keys as $key) {
                $value = $spanish[$key] ?? null;

                if ($value === null || ($locales[$locale][$key] ?? null) !== $value || $value === ($english[$key] ?? null) || mb_strlen($value) <= 12) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Spanish allow-list entries that no longer exist or no longer equal the Spanish value');
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
