.PHONY: help up down build install shell logs test test-coverage cache-clear clear-data reset-demo link-bundle update-bundle ensure-up restart release-check sync-schema

help:
	@echo "Symfony 8 Demo - Uptime Monitor Bundle"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@ready=0; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30; do \
		if $(COMPOSE) exec -T php true >/dev/null 2>&1; then ready=1; break; fi; \
		sleep 1; \
	done; \
	if [ "$$ready" != "1" ]; then \
		echo "ERROR: php service is not running"; \
		$(COMPOSE) ps -a; \
		$(COMPOSE) logs php --tail 80 || true; \
		exit 1; \
	fi
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer update --no-interaction
	@$(MAKE) link-bundle
	@echo "Waiting for MySQL from PHP container..."
	@i=0; \
	while [ $$i -lt 30 ]; do \
		if $(COMPOSE) exec -T php php bin/console nowo:uptime:sync-schema 2>/dev/null; then \
			break; \
		fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	if [ $$i -ge 30 ]; then \
		echo "MySQL not reachable from demo PHP container"; \
		$(COMPOSE) logs --tail=50 database php || true; \
		exit 1; \
	fi
	@$(MAKE) reset-demo
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then $(COMPOSE) up -d; sleep 3; fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	$(COMPOSE) exec php composer test-coverage

cache-clear: ensure-up
	$(COMPOSE) exec php php bin/console cache:clear

clear-data: ensure-up
	$(COMPOSE) exec -T php php bin/console nowo:uptime:clear-data --no-interaction

sync-schema: ensure-up
	$(COMPOSE) exec -T php php bin/console nowo:uptime:sync-schema

reset-demo: ensure-up
	$(COMPOSE) exec -T php php bin/console nowo:uptime:seed-demo --fresh --no-interaction
	$(COMPOSE) exec -T php php bin/console nowo:uptime:run-due-checks

link-bundle: ensure-up
	$(COMPOSE) exec php sh -c "rm -rf vendor/nowo-tech/uptime-monitor-bundle && ln -s /var/uptime-monitor-bundle vendor/nowo-tech/uptime-monitor-bundle && php bin/console cache:clear && php bin/console assets:install public"

update-bundle: ensure-up
	$(COMPOSE) exec php composer update nowo-tech/uptime-monitor-bundle --no-interaction
	$(COMPOSE) exec php sh -c "php bin/console cache:clear --env=dev && php bin/console assets:install public"

release-check: ensure-up update-bundle test test-coverage

# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell PATH="/usr/local/bin:/usr/bin:/bin:$$PATH" command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
SERVICE_PHP := php
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
