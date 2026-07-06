<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Translation;

/**
 * Translation domain and supported locales for the bundle UI.
 */
final class UptimeTranslation
{
    public const DOMAIN = 'NowoUptimeMonitorBundle';

    /** @var list<string> */
    public const LOCALES = ['en', 'es'];

    public const DEFAULT_LOCALE = 'en';
}
