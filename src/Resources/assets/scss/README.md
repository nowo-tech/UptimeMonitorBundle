# Bundle styles (Sass)

| Entry | Output | Loaded from |
|-------|--------|-------------|
| `uptime-dashboard.scss` | `public/uptime-dashboard.css` | `_stylesheets.html.twig` (via TS import in `uptime-dashboard.ts`) |
| `uptime-theme.scss` | `public/uptime-theme.css` | `_stylesheets.html.twig` |
| `_host-theme.scss` | (part of `uptime-theme.css`) | Host shell: add `uptime-app--embedded` on root wrapper |
| `uptime-ui-bootstrap.scss` | `public/uptime-ui-bootstrap.css` | when `ui.framework: bootstrap` |
| `uptime-ui-tailwind.scss` | `public/uptime-ui-tailwind.css` | when `ui.framework: tailwind` |

Partials: `_variables.scss`, `_mixins.scss`, `_base.scss`, `_kuma.scss`, `_forms.scss`, `_settings.scss`.

```bash
make assets   # or: pnpm run build
```
