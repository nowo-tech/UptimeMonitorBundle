.PHONY: help up down build shell ensure-up install assets test test-coverage test-coverage-90 test-coverage-100 test-ts cs-check cs-fix phpstan rector rector-dry qa composer-sync release-check release-check-demos validate-translations clean update validate test-with-db test-coverage-with-db setup-hooks

COMPOSE_FILE ?= docker-compose.yml
COMPOSE     ?= docker-compose -f $(COMPOSE_FILE)
SERVICE_PHP ?= php

help:
	@echo "Uptime Monitor Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up, down, build, shell, install, assets"
	@echo "  test, test-coverage, test-coverage-90, test-coverage-100, test-ts"
	@echo "  test-with-db, test-coverage-with-db"
	@echo "  cs-check, cs-fix, phpstan, rector, rector-dry, qa"
	@echo "  release-check, release-check-demos, composer-sync, validate-translations"
	@echo "  setup-hooks, clean, update, validate"
	@echo ""
	@echo "Demos: make -C demo up-symfony7 | up-symfony8 | clear-data-symfony8"

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

test-with-db: test

test-coverage-with-db: test-coverage

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
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --lock --no-install --no-interaction

release-check-demos:
	@$(MAKE) -C demo release-check

release-check: ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage-90 release-check-demos test-ts

clean:
	rm -rf vendor coverage coverage-ts .phpunit.cache coverage-php.txt coverage-ts.txt node_modules

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate:
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

validate-translations:
	@test -f src/Resources/translations/uptime.en.yaml
	@test -f src/Resources/translations/uptime.es.yaml
	@echo "Translation catalogues: uptime.en.yaml, uptime.es.yaml (domain: uptime)"

setup-hooks:
	@if [ -d .githooks ]; then \
		chmod +x .githooks/pre-commit; \
		git config core.hooksPath .githooks; \
		echo "Git hooks installed."; \
	else \
		echo "No .githooks directory found."; \
	fi

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
