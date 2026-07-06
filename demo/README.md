# Uptime Monitor Bundle — Demos

Dos demos FrankenPHP con **Mercure** para sincronización en tiempo real del dashboard.

| Demo | Symfony | Dashboard | Hub SSE (navegador) |
|------|---------|-----------|------------------------|
| [symfony7](symfony7/) | 7.2 | http://localhost:8010/uptime/main | http://localhost:8010/.well-known/mercure |
| [symfony8](symfony8/) | 8.0 | http://localhost:8011/uptime/main | http://localhost:8011/.well-known/mercure |

Documentación detallada de Mercure en las demos: **[MERCURE.md](MERCURE.md)** (arquitectura, JWT, proxy Caddy, troubleshooting).

## Inicio rápido

```bash
# Symfony 8 + Mercure (recomendado)
make -C demo up-symfony8

# Symfony 7 + Mercure
make -C demo up-symfony7
```

El dashboard muestra **Mercure · connected** cuando el SSE está abierto. Cada `up` ejecuta **`reset-demo`**: 1 grupo `[demo]` + 2 monitores HTTP (`demo_uptime_ok`, `demo_uptime_flaky` → rutas `/demo/uptime/ok` y `/demo/uptime/flaky/3`).

```bash
# Re-seed desde cero
make -C demo reset-demo-symfony8

# Solo borrar historial de checks
make -C demo clear-data-symfony8
```

## Mercure (resumen)

- Hub Docker en puerto **3080** (symfony8) / **3081** (symfony7); el **navegador** usa el proxy en **8011** / **8010** (mismo origen + cookie JWT).
- **`checks-worker`**: publica en Mercure tras cada check (`entrypoint: []` en compose).
- **`MERCURE_PUBLIC_URL`**: debe ser `http://localhost:8011/.well-known/mercure` (no solo `:3080`).
- Config: `config/packages/nowo_uptime_monitor.yaml` → `sync: mercure`.

Ver [MERCURE.md](MERCURE.md) para diagrama, variables, seguridad y errores habituales.

## Configuración en la app demo

```yaml
# config/packages/nowo_uptime_monitor.yaml
dashboard:
    sync: mercure
    mercure:
        topic_template: '/uptime/{tenant}'
```

```env
# .env — symfony8
MERCURE_URL=http://mercure/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:8011/.well-known/mercure
MERCURE_JWT_SECRET=!ChangeThisMercureHubJWTSecretKey!
```

Integración en proyectos host: [../docs/MERCURE.md](../docs/MERCURE.md).
