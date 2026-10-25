# ecommerce-core — single entry point for local operations (R20).
# Every target shells out to docker/docker compose only: no PHP, Composer or vendor/bin on the host (INF-002).
#
#   make help            list the targets below
#   make up              start app + worker + outbox + database + meilisearch
#   make console ARGS="…" / make composer ARGS="…"
#   make ci              the same command list CI runs (build → install → analysis → migrations → suites → harness)

SHELL := /bin/sh
.DEFAULT_GOAL := help

COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T app

APP_PORT   ?= $(shell sed -n 's/^APP_PORT=//p' .env 2>/dev/null | head -n1)
APP_PORT   := $(if $(APP_PORT),$(APP_PORT),8081)
MEILI_PORT ?= $(shell sed -n 's/^MEILI_PORT=//p' .env 2>/dev/null | head -n1)
MEILI_PORT := $(if $(MEILI_PORT),$(MEILI_PORT),7700)

ARGS ?=

.PHONY: help build up down ps logs sh composer install console migrate migrate-test \
        test test-unit test-integration test-projection test-functional test-contract test-architecture test-all \
        cs cs-fix phpstan deptrac infection harness ci db-backup db-restore urls

help: ## Show the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Build the dev image (target: dev)
	$(COMPOSE) build

up: ## Start the stack and wait for the healthchecked services
	# Compose --wait refuses services without a healthcheck, and the CLI consumers have none.
	$(COMPOSE) up -d --wait app database meilisearch
	$(COMPOSE) up -d worker outbox

down: ## Stop and remove the containers (named volumes are kept)
	$(COMPOSE) down

ps: ## Show service status
	$(COMPOSE) ps

logs: ## Follow logs; ARGS="worker outbox" narrows to services
	$(COMPOSE) logs -f --tail=100 $(ARGS)

sh: ## Open a shell in the app container
	$(COMPOSE) exec app sh

composer: ## Run composer in the app container: make composer ARGS="require foo/bar"
	$(EXEC) composer $(ARGS)

install: up ## Install composer dependencies from composer.lock
	$(EXEC) composer install --no-interaction --prefer-dist

console: ## Run bin/console: make console ARGS="cache:clear"
	$(EXEC) php bin/console $(ARGS)

migrate: up ## Apply the migrations to the dev database, then approve the catalogue that predates the workflow
	$(EXEC) php bin/console doctrine:migrations:migrate -n --allow-no-migration
	$(EXEC) php bin/console app:catalog:backfill-approval --apply

migrate-test: up ## Apply the migrations to the test database, then approve the catalogue that predates the workflow
	$(EXEC) php bin/console doctrine:database:create --if-not-exists --env=test
	$(EXEC) php bin/console doctrine:migrations:migrate -n --allow-no-migration --env=test
	$(EXEC) php bin/console app:catalog:backfill-approval --apply --env=test

test: up ## Run every PHPUnit suite
	$(EXEC) vendor/bin/phpunit

test-unit: up ## Run the Unit suite
	$(EXEC) vendor/bin/phpunit --testsuite Unit

test-integration: up ## Run the Integration suite
	$(EXEC) vendor/bin/phpunit --testsuite Integration

test-projection: up ## Run the Projection suite
	$(EXEC) vendor/bin/phpunit --testsuite Projection

test-functional: up ## Run the Functional suite
	$(EXEC) vendor/bin/phpunit --testsuite Functional

test-contract: up ## Run the Contract suite
	$(EXEC) vendor/bin/phpunit --testsuite Contract

test-architecture: up ## Run the Architecture suite
	$(EXEC) vendor/bin/phpunit --testsuite Architecture

# Unlike `make test`, a red suite does not stop the run: every suite executes and the
# report at the end lists each failing test (scripts/run-tests.sh).
test-all: up ## Run every suite, keep going after a failure, then list every failing test
	./scripts/run-tests.sh

cs: up ## Check the code style (php-cs-fixer --dry-run)
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php

cs-fix: up ## Apply the code style fixes
	$(EXEC) vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

phpstan: up ## Run PHPStan
	$(EXEC) vendor/bin/phpstan analyse --no-progress

deptrac: up ## Run the layer dependency analysis
	$(EXEC) vendor/bin/deptrac analyse --no-progress

infection: up ## Run Infection (needs a coverage driver: docker compose build --build-arg INSTALL_XDEBUG=true app)
	$(EXEC) vendor/bin/infection --no-progress

harness: up ## Check the .agents harness integrity
	$(EXEC) bin/harness-check

ci: build up ## Reproduce the CI quality job locally, same order
	$(EXEC) composer validate --no-check-publish
	$(EXEC) composer install --no-interaction --prefer-dist
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.dist.php
	$(EXEC) vendor/bin/phpstan analyse --no-progress
	$(EXEC) vendor/bin/deptrac analyse --no-progress
	$(EXEC) php bin/console doctrine:migrations:migrate -n --allow-no-migration
	$(EXEC) php bin/console app:catalog:backfill-approval --apply
	$(EXEC) php bin/console doctrine:database:create --if-not-exists --env=test
	$(EXEC) php bin/console doctrine:migrations:migrate -n --allow-no-migration --env=test
	$(EXEC) php bin/console app:catalog:backfill-approval --apply --env=test
	$(EXEC) vendor/bin/phpunit --testsuite Unit
	$(EXEC) vendor/bin/phpunit --testsuite Integration
	$(EXEC) vendor/bin/phpunit --testsuite Projection
	$(EXEC) vendor/bin/phpunit --testsuite Functional
	$(EXEC) vendor/bin/phpunit --testsuite Contract
	$(EXEC) vendor/bin/phpunit --testsuite Architecture
	$(EXEC) bin/harness-check

db-backup: ## Dump the database and the uploads volume into backups/ (scripts/backup-db.sh)
	./scripts/backup-db.sh $(ARGS)

db-restore: ## Restore a dump: make db-restore ARGS="backups/db-<timestamp>.dump --yes"
	./scripts/restore-db.sh $(ARGS)

urls: ## Print the local URLs
	@printf 'API:           http://localhost:%s\n' '$(APP_PORT)'
	@printf 'Swagger UI:    http://localhost:%s/api/doc\n' '$(APP_PORT)'
	@printf 'OpenAPI JSON:  http://localhost:%s/api/doc.json\n' '$(APP_PORT)'
	@printf 'Meilisearch:   http://localhost:%s\n' '$(MEILI_PORT)'
