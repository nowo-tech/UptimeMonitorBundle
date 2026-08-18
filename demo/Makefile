DEMOS := symfony8

# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell PATH="/usr/local/bin:/usr/bin:/bin:$$PATH" command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif

.PHONY: help up down test test-coverage release-check demo-smoke $(foreach demo,$(DEMOS),up-$(demo) down-$(demo) test-$(demo) test-coverage-$(demo) clear-data-$(demo) reset-demo-$(demo) release-verify-$(demo))

help:
	@echo "Uptime Monitor Bundle Demo"
	@echo "make up-symfony8 | down-symfony8"
	@echo "make clear-data-symfony8  # wipe checks/aggregates/incidents"
	@echo "make reset-demo-symfony8  # [demo] + demo_uptime_ok + demo_uptime_flaky + first checks"
	@echo "make release-check  # all demos"
	@echo "make demo-smoke     # REQ-TEST-011: HTTP 200 on /uptime/main"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

clear-data-symfony8:
	@$(MAKE) -C symfony8 clear-data

reset-demo-symfony8:
	@$(MAKE) -C symfony8 reset-demo

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) up

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) down

test:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test DEMO=symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) test

test-coverage:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test-coverage DEMO=symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) test-coverage

release-verify-symfony8:
	@$(MAKE) -C symfony8 ensure-up update-bundle
	@$(MAKE) -C symfony8 sync-schema reset-demo
	@PORT=$$(grep '^PORT=' symfony8/.env.example | cut -d= -f2); \
	curl -sf "http://localhost:$$PORT/uptime/main" >/dev/null && \
	curl -sf "http://localhost:$$PORT/status/main" >/dev/null

# REQ-TEST-011: boot primary demo + HTTP 200
demo-smoke:
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8011; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT/uptime/main)"; \
	$(MAKE) -C symfony8 up || exit 1; \
	code=000; \
	i=0; \
	while [ $$i -lt 45 ]; do \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/uptime/main" || true); \
		[ -z "$$code" ] && code=000; \
		if [ "$$code" = "200" ]; then break; fi; \
		i=$$((i+1)); \
		sleep 2; \
	done; \
	$(MAKE) -C symfony8 down 2>/dev/null || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"

release-check:
	@$(MAKE) -C symfony8 release-check
	@$(MAKE) release-verify-symfony8

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
