DEMOS := symfony7 symfony8

.PHONY: help up down test test-coverage release-check $(foreach demo,$(DEMOS),up-$(demo) down-$(demo) test-$(demo) test-coverage-$(demo) clear-data-$(demo) reset-demo-$(demo) release-verify-$(demo))

help:
	@echo "Uptime Monitor Bundle Demo"
	@echo "make up-symfony7 | up-symfony8 | down-symfony7 | down-symfony8"
	@echo "make clear-data-symfony7 | clear-data-symfony8  # wipe checks/aggregates/incidents"
	@echo "make reset-demo-symfony8  # [demo] + demo_uptime_ok + demo_uptime_flaky + first checks"
	@echo "make release-check  # all demos"

up-symfony7:
	@$(MAKE) -C symfony7 up

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony7:
	@$(MAKE) -C symfony7 down

down-symfony8:
	@$(MAKE) -C symfony8 down

test-symfony7:
	@$(MAKE) -C symfony7 test

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony7:
	@$(MAKE) -C symfony7 test-coverage

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

clear-data-symfony7:
	@$(MAKE) -C symfony7 clear-data

clear-data-symfony8:
	@$(MAKE) -C symfony8 clear-data

reset-demo-symfony7:
	@$(MAKE) -C symfony7 reset-demo

reset-demo-symfony8:
	@$(MAKE) -C symfony8 reset-demo

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) up

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) down

test:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) test

test-coverage:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test-coverage DEMO=symfony7|symfony8"; exit 1; }
	@$(MAKE) -C $(DEMO) test-coverage

release-verify-symfony7:
	@$(MAKE) -C symfony7 ensure-up update-bundle
	@PORT=$$(grep '^PORT=' symfony7/.env.example | cut -d= -f2); \
	curl -sf "http://localhost:$$PORT/uptime/main" >/dev/null && \
	curl -sf "http://localhost:$$PORT/status/main" >/dev/null

release-verify-symfony8:
	@$(MAKE) -C symfony8 ensure-up update-bundle
	@PORT=$$(grep '^PORT=' symfony8/.env.example | cut -d= -f2); \
	curl -sf "http://localhost:$$PORT/uptime/main" >/dev/null && \
	curl -sf "http://localhost:$$PORT/status/main" >/dev/null

release-check:
	@$(MAKE) -C symfony7 release-check
	@$(MAKE) -C symfony8 release-check
	@$(MAKE) release-verify-symfony7
	@$(MAKE) release-verify-symfony8

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../.scripts/Makefile.demo-aggregate-update-deps.mk
