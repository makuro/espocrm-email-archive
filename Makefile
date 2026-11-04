COMPOSE := podman-compose
EXEC := podman exec -u www-data -w /var/www/html espo-archive_espocrm_1
SCALE ?= 1

.PHONY: up down reset seed measure check package shell db cc logs

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

# Drops all volumes and starts a fresh instance.
reset:
	$(COMPOSE) down -v
	$(COMPOSE) up -d
	@until curl -sf -o /dev/null http://127.0.0.1:8080/; do sleep 3; done

seed: cc
	$(EXEC) bin/command dev-seed --scale=$(SCALE)

measure:
	./dev/measure/measure.sh

# Integration check (move + retention on fixtures) and API access check.
check: cc
	$(EXEC) bin/command dev-archive-check
	./dev/measure/acl-check.sh

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
	podman exec -it -u www-data -w /var/www/html espo-archive_espocrm_1 bash

db:
	podman exec -it espo-archive_db_1 sh -c 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" espocrm'

logs:
	podman logs -f espo-archive_espocrm_1
