# Coding9 Agent Ready — developer Makefile
#
# Usage:
#   make help        # list every target
#   make test        # run PHPUnit
#   make zip         # build the release zip into .build/
#   make sandbox     # spin up a Shopware 6.7 sandbox via shopware-cli
#   make smoke       # install zip into sandbox and curl every endpoint

PLUGIN_NAME    := Coding9AgentReady
VERSION        := $(shell php -r 'echo json_decode(file_get_contents("composer.json"))->version;')
ZIP_NAME       := $(PLUGIN_NAME)-$(VERSION).zip
BUILD_DIR      := .build
ZIP_PATH       := $(BUILD_DIR)/$(ZIP_NAME)
SANDBOX_DIR    := .sandbox
SANDBOX_HTTP   := http://127.0.0.1:8000

PHP            ?= php
COMPOSER       ?= composer
PHPUNIT        := vendor/bin/phpunit
PHPSTAN        := vendor/bin/phpstan
SHOPWARE_CLI   ?= shopware-cli

.DEFAULT_GOAL := help

.PHONY: help install test phpstan validate prepare build zip release \
        sandbox sandbox-stop sandbox-clean smoke clean

help: ## list available targets
	@awk 'BEGIN{FS=":.*##"; printf "\nTargets:\n"} /^[a-zA-Z_-]+:.*##/{printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

install: ## composer install
	$(COMPOSER) install --prefer-dist --no-progress --no-interaction

test: ## run PHPUnit
	$(PHPUNIT) --testdox

phpstan: ## static analysis
	$(PHPSTAN) analyse --no-progress

validate: ## shopware-cli extension validate
	$(SHOPWARE_CLI) extension validate .

prepare: ## shopware-cli extension prepare (install ext deps)
	$(SHOPWARE_CLI) extension prepare .

build: prepare ## shopware-cli extension build (compile assets)
	$(SHOPWARE_CLI) extension build .

zip: ## produce release zip in .build/
	@mkdir -p $(BUILD_DIR)
	@rm -f $(ZIP_PATH)
	$(SHOPWARE_CLI) extension zip --release . --output-directory $(BUILD_DIR)
	@echo ""
	@echo "==> $(ZIP_PATH) ($$(du -h $(ZIP_PATH) | cut -f1))"

release: test phpstan validate zip ## full quality gate + zip

sandbox: ## start a Shopware 6.7 sandbox at $(SANDBOX_HTTP)
	@if [ ! -d $(SANDBOX_DIR) ]; then \
	    $(SHOPWARE_CLI) project create $(SANDBOX_DIR) 6.7 ; \
	fi
	@cd $(SANDBOX_DIR) && $(SHOPWARE_CLI) project ci . && \
	    bin/console system:install --basic-setup --create-database || true
	@echo "==> sandbox up at $(SANDBOX_HTTP)"

sandbox-stop: ## stop the sandbox web server (best effort)
	@pkill -f "php -S 127.0.0.1:8000" || true

sandbox-clean: sandbox-stop ## remove sandbox completely
	rm -rf $(SANDBOX_DIR)

smoke: zip ## install zip into sandbox and curl every endpoint
	@if [ ! -d $(SANDBOX_DIR) ]; then echo "run 'make sandbox' first" && exit 1; fi
	cp $(ZIP_PATH) $(SANDBOX_DIR)/custom/plugins/
	cd $(SANDBOX_DIR) && unzip -o custom/plugins/$(ZIP_NAME) -d custom/plugins/ >/dev/null
	cd $(SANDBOX_DIR) && bin/console plugin:refresh
	cd $(SANDBOX_DIR) && bin/console plugin:install --activate --clearCache $(PLUGIN_NAME)
	cd $(SANDBOX_DIR) && (bin/console server:start 127.0.0.1:8000 -d 2>/dev/null || $(PHP) -S 127.0.0.1:8000 -t public >/tmp/sw.log 2>&1 &) ; sleep 3
	@echo ""
	@echo "==> Link headers"
	curl -sI $(SANDBOX_HTTP)/ | grep -i '^link:' || echo "  (no Link header)"
	@echo ""
	@echo "==> /.well-known/api-catalog"
	curl -s $(SANDBOX_HTTP)/.well-known/api-catalog | head -c 400 ; echo
	@echo ""
	@echo "==> /.well-known/agent-card.json"
	curl -s $(SANDBOX_HTTP)/.well-known/agent-card.json | head -c 400 ; echo
	@echo ""
	@echo "==> /robots.txt"
	curl -s $(SANDBOX_HTTP)/robots.txt
	@echo ""
	@echo "==> Markdown negotiation"
	curl -sH 'Accept: text/markdown' $(SANDBOX_HTTP)/ -o /tmp/md && head -c 400 /tmp/md ; echo

clean: ## remove build artefacts
	rm -rf $(BUILD_DIR) .phpunit.cache .phpunit.result.cache var/cache var/log
