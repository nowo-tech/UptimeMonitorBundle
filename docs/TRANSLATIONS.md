# Translations

## Table of contents

- [Files](#files)
- [Locale (session)](#locale-session)
- [Forms](#forms)
- [Twig](#twig)
- [Host application](#host-application)

## Files

| File | Locale / purpose |
|------|------------------|
| `src/Resources/translations/NowoUptimeMonitorBundle.en.yaml` | English UI |
| `src/Resources/translations/NowoUptimeMonitorBundle.es.yaml` | Spanish UI |
| `src/Resources/translations/NowoUptimeMonitorBundle.{de,fr,it,nl,pt}.yaml` | Additional UI locales |
| `src/Resources/translations/validators.*.yaml` | Validator messages |

## Locale (session)

The bundle UI uses the **Symfony request locale** from the host application (session, `_locale` route parameter, `LocaleListener`, or your locale switcher). It is **not** stored in tenant settings.

**Settings → Appearance** displays the current locale read-only. Change language in your app (e.g. `/es/…` prefix or a locale switcher), then reload the uptime pages.

## Forms

All bundle form types extend `AbstractUptimeFormType`, which sets `translation_domain: NowoUptimeMonitorBundle` (or the configured domain). Labels and help texts are message keys (e.g. `form.monitor.name`).

## Twig

Templates use `{% trans_default_domain 'NowoUptimeMonitorBundle' %}`.

**Important:** Twig does not propagate `trans_default_domain` from the layout into **child blocks**. Put `{% trans_default_domain 'NowoUptimeMonitorBundle' %}` as the first line inside each `{% block %}` that uses `|trans` (not after `{% extends %}` outside blocks — Twig 3 forbids that).

## Host application

Enable the translator in the host (`framework.translator`). The bundle does not prepend any translator settings: its catalogue (`src/Resources/translations`) is auto-discovered by FrameworkBundle, and the host's `fallbacks` / `default_locale` / `enabled_locales` are left untouched. Add `en` to `framework.translator.fallbacks` if you want English labels for locales the bundle does not ship.

To add a locale:

1. Copy `NowoUptimeMonitorBundle.en.yaml` to `NowoUptimeMonitorBundle.{locale}.yaml` and translate.
2. Add the locale to `enabled_locales` if you customize it.
3. Run `make validate-translations` from the bundle root.
