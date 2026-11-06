<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Ui;

/**
 * Host hook `web_ui.settings.sections_resolver` (since 1.21): the settings screens of one tenant — e.g. none for a
 * personal tenant. The result narrows `web_ui.settings.sections` (unknown or unconfigured ids are ignored, the bundle
 * order is kept); a screen left out answers 404 and leaves the tabs, and with none left the Settings links go too.
 */
interface SettingsSectionsResolverInterface
{
    /**
     * The section ids (`general`, `appearance`, … `about`) this tenant shows.
     *
     * @return list<string>
     */
    public function sections(string $tenantSlug): array;
}
