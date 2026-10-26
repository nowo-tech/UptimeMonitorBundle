.PHONY: help up down build shell ensure-up install assets test test-coverage test-coverage-90 test-coverage-100 test-ts cs-check cs-fix rector rector-dry phpstan igor qa composer-sync release-check release-check-demos demo-smoke validate-translations clean update validate test-with-db test-coverage-with-db setup-hooks check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history check-twig-extra check-no-raw-html-form twig-lint

COMPOSE_FILE ?= docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     ?= $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP ?= php

help:
	@echo "Uptime Monitor Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Container:"
	@echo "  up                    Start the Docker environment (build, composer + pnpm install, assets)"
	@echo "  down                  Stop the Docker environment"
	@echo "  build                 Rebuild the Docker image (no cache)"
	@echo "  shell                 Open a shell in the PHP container"
	@echo ""
	@echo "Dependencies:"
	@echo "  install               composer install + pnpm install in the container"
	@echo ""
	@echo "Assets:"
	@echo "  assets                Build the dashboard JS/CSS (pnpm run build)"
	@echo ""
	@echo "Tests:"
	@echo "  test                  Run PHPUnit (SQLite in-memory for DB-backed tests)"
	@echo "  test-coverage         Run PHPUnit with coverage (text + HTML + Clover)"
	@echo ""
	@echo "Quality:"
	@echo "  cs-check              PHP-CS-Fixer dry-run"
	@echo "  cs-fix                PHP-CS-Fixer fix"
	@echo "  rector                Apply Rector"
	@echo "  rector-dry            Rector dry-run"
	@echo "  phpstan               PHPStan static analysis"
	@echo "  igor                  Igor worker-state audit (REQ-CS-008)"
	@echo "  qa                    cs-check + test"
	@echo ""
	@echo "Release:"
	@echo "  release-check         Full pre-release pipeline (REQ-MAKE-002)"
	@echo "  composer-sync         composer validate --strict + align composer.lock"
	@echo ""
	@echo "Cleanup:"
	@echo "  clean                 Remove vendor, node_modules, coverage and caches"
	@echo ""
	@echo "Composer:"
	@echo "  update                composer update in the bundle container"
	@echo "  update-deps           composer update in the bundle and every demo (REQ-MAKE-008)"
	@echo "  validate              composer validate --strict"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  test-with-db          PHPUnit against the MySQL and PostgreSQL services of docker-compose.yml (REQ-TEST-005)"
	@echo "  test-coverage-with-db Same as test-with-db, with text coverage"
	@echo "  test-coverage-90      test-coverage + fail under 90% lines"
	@echo "  test-coverage-100     test-coverage + fail under 100% lines"
	@echo "  test-ts               Vitest with coverage (dashboard TypeScript)"
	@echo "  validate-translations YAML syntax + key parity of NowoUptimeMonitorBundle.<locale>.yaml (REQ-MAKE-004)"
	@echo "  twig-lint             Twig-CS-Fixer lint"
	@echo "  check-twig-extra      Twig extra filters/functions are required"
	@echo "  check-no-raw-html-form Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  check-open-prs        Fail on unresolved open GitHub PRs (REQ-REL-003)"
	@echo "  check-no-cursor-coauthor  Fail on Cursor co-author trailers (REQ-GIT-001)"
	@echo "  strip-cursor-coauthor-from-history  Rewrite history without Cursor trailers"
	@echo "  setup-hooks           Install git hooks (.githooks)"
	@echo ""
	@echo "Demos:"
	@echo "  release-check-demos   Demo release-check (update bundle, tests, HTTP verify)"
	@echo "  demo-smoke            Boot demo/symfony8 and expect HTTP 200 on /uptime/main (REQ-TEST-011)"
	@echo "  make -C demo up-symfony8 | down-symfony8 | reset-demo-symfony8 | clear-data-symfony8"

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 10
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	$(MAKE) assets

down:
	$(COMPOSE) down

build:
	$(COMPOSE) build --no-cache

shell: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) sh

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 10; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
		$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install; \
	fi
	@$(COMPOSE) exec -T $(SERVICE_PHP) git config --global --add safe.directory /app 2>/dev/null || true

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install

assets: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm run build

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	@./.scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-90: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-90

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer test-coverage-100

test-ts: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm run test:coverage | tee coverage-ts.txt
	@./.scripts/ts-coverage-percent.sh coverage-ts.txt

# REQ-TEST-005: the suite against the real databases of docker-compose.yml (DATABASE_URL = MySQL,
# DATABASE_URL_PG = PostgreSQL). Tests that use tests/Support/SqliteEntityManagerFactory switch from SQLite
# in-memory to UPTIME_TEST_DATABASE_URL (schema dropped and recreated per test).
test-with-db: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'UPTIME_TEST_DATABASE_URL="$$DATABASE_URL" composer test'
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'UPTIME_TEST_DATABASE_URL="$$DATABASE_URL_PG" composer test'

test-coverage-with-db: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'UPTIME_TEST_DATABASE_URL="$$DATABASE_URL" php vendor/bin/phpunit --color=always --coverage-text'
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'UPTIME_TEST_DATABASE_URL="$$DATABASE_URL_PG" php vendor/bin/phpunit --color=always --coverage-text'

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

release-check-demos:
	@$(MAKE) -C demo release-check

demo-smoke:
	@$(MAKE) -C demo demo-smoke


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations test-coverage-90 release-check-demos test-ts

clean:
	rm -rf vendor coverage coverage-ts .phpunit.cache coverage-php.txt coverage-ts.txt node_modules

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate:
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

# REQ-MAKE-004: YAML syntax, NowoUptimeMonitorBundle-only catalogues, key parity across locales (exit != 0 on error).
validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/yaml-lint src/Resources/translations
	$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
