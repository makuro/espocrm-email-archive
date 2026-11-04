-include .env

# Database: mariadb (default), mysql or postgres. Each gets its own compose project and volumes.
DB ?= mariadb
PROJECT := espo-archive$(if $(filter-out mariadb,$(DB)),-$(DB))
COMPOSE := podman-compose -p $(PROJECT) -f compose.yaml $(if $(filter-out mariadb,$(DB)),-f dev/compose/$(DB).yaml)
EXEC := podman exec -u www-data -w /var/www/html $(PROJECT)_espocrm_1
SCALE ?= 1

export DB PROJECT

.PHONY: up down reset seed measure check ci lifecycle package shell db cc logs

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

# Drops all volumes and starts a fresh instance.
reset:
	$(COMPOSE) down -v
	$(COMPOSE) up -d
	@until curl -sf -o /dev/null http://127.0.0.1:$(ESPO_PORT)/; do sleep 3; done

seed: cc
	$(EXEC) bin/command dev-seed --scale=$(SCALE)

measure:
	./dev/measure/measure.sh

# Integration check (move + retention on fixtures) and API access check.
check: cc
	$(EXEC) bin/command dev-archive-check
	./dev/measure/acl-check.sh

# Full functional test on a fresh instance, as run by CI. Destroys the instance's data.
ci: reset
	./dev/ci/functional.sh

# Install, upgrade, uninstall and reinstall the package on a separate instance (port 8091).
lifecycle: package
	./dev/ci/lifecycle.sh

# Installable extension package (Administration > Extensions).
VERSION := $(shell python3 -c "import json; print(json.load(open('extension/src/manifest.json'))['version'])")
package:
	@mkdir -p build
	rm -f build/email-archive-$(VERSION).zip
	cd extension/src && zip -qr ../../build/email-archive-$(VERSION).zip manifest.json files scripts -x '.*'
	@echo build/email-archive-$(VERSION).zip

cc:
	$(EXEC) bin/command clear-cache

shell:
	podman exec -it -u www-data -w /var/www/html $(PROJECT)_espocrm_1 bash

db:
ifeq ($(DB),postgres)
	podman exec -it $(PROJECT)_db_1 psql -U espocrm espocrm
else
	podman exec -it $(PROJECT)_db_1 sh -c '$(if $(filter mysql,$(DB)),mysql,mariadb) -uroot -p"$$$(if $(filter mysql,$(DB)),MYSQL,MARIADB)_ROOT_PASSWORD" espocrm'
endif

logs:
	podman logs -f $(PROJECT)_espocrm_1
