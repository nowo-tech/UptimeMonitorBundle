# Translations

The bundle UI uses Symfony Translation with domain **`uptime`** and locales **`en`** (default) and **`es`**.

## Files

| File | Locale |
|------|--------|
| `src/Resources/translations/uptime.en.yaml` | English |
| `src/Resources/translations/uptime.es.yaml` | Spanish |
| `src/Resources/translations/validators.en.yaml` | Validator messages (English) |
| `src/Resources/translations/validators.es.yaml` | Validator messages (Spanish) |

## Locale (session)

The bundle UI uses the **Symfony request locale** from the host application (session, `_locale` route parameter, `LocaleListener`, or your locale switcher). It is **not** stored in tenant settings.

**Settings → Appearance** displays the current locale read-only. Change language in your app (e.g. `/es/…` prefix or a locale switcher), then reload the uptime pages.

## Forms

All bundle form types extend `AbstractUptimeFormType`, which sets `translation_domain: uptime`. Labels and help texts are message keys (e.g. `form.monitor.name`).

## Twig

Templates use the `uptime` domain via `{% trans_default_domain 'NowoUptimeMonitorBundle' %}`.

**Important:** Twig does not propagate `trans_default_domain` from the layout into **child blocks**. Put `{% trans_default_domain 'NowoUptimeMonitorBundle' %}` as the first line inside each `{% block %}` that uses `|trans` (not after `{% extends %}` outside blocks — Twig 3 forbids that).

## Host application

The bundle prepends (when `framework` is available):

```yaml
framework:
    default_locale: en
    enabled_locales: ['en', 'es']
    translator:
        fallbacks: ['en']
```

Demos already enable `en` and `es` in `config/packages/framework.yaml`. Ensure `symfony/translation` is installed (required by the bundle).

## Adding a locale

1. Copy `uptime.en.yaml` to `uptime.{locale}.yaml` and translate.
2. Add the locale to `UptimeTranslation::LOCALES`.
3. Add `enabled_locales` in the host app `framework` config.

## Validate catalogues

```bash
# In the host app (after composer require symfony/translation)
php bin/console debug:translation uptime --only-missing en
php bin/console debug:translation uptime --only-missing es
```

From the bundle repo:

```bash
make validate-translations
```
