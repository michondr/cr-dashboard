# cr-dashboard development Makefile.
#
# The static PHP build and Composer are not on PATH on this machine, so both
# are resolved here. Override per invocation when needed:
#
#   make test            # this machine's default PHP/Composer
#   make test PHP=php    # a PHP that IS on PATH
#
# The quality gate mirrors what CI enforces: `make gate` before committing.

.PHONY: help install update test lint phpcs phpcs-fix phpstan gate \
	fixture test-frontend test-smoke check \
	migrate migrate-status migrate-diff db-reset cache-clear \
	sync sync-full rank-users gitlab-probe serve \
	docker-build docker-up docker-down docker-down-clean docker-logs \
	docker-exec docker-migrate docker-sync docker-sync-full docker-rank-users

.DEFAULT_GOAL := help

# --- Tooling (overridable) ---------------------------------------------------

PHP       ?= /home/paseo/tools/php/php8.5
COMPOSER  ?= /home/paseo/tools/php/composer

CONSOLE  := $(PHP) bin/console
BIN      := vendor/bin
FRONTEND := tests/frontend

# Playwright's headless Chromium needs system libs + fonts that the sandbox
# does not ship with; without these it fails to launch or renders zero-height
# text. Kept here so `make test-smoke` just works.
PLAYWRIGHT_ENV := LD_LIBRARY_PATH=/tmp/cr-libs/root/usr/lib/x86_64-linux-gnu:/tmp/cr-libs/root/lib/x86_64-linux-gnu FONTCONFIG_PATH=/tmp/cr-libs/fontconfig

DOCKER_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.override.yml

# --- Help --------------------------------------------------------------------

help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- PHP dependencies ----------------------------------------------------------

install: ## Install PHP dependencies (composer install)
	$(PHP) $(COMPOSER) install

update: ## Update PHP dependencies (composer update)
	$(PHP) $(COMPOSER) update

# --- Quality gate -------------------------------------------------------------

test: ## Run the PHPUnit suite (ARGS="--filter=...")
	$(PHP) $(BIN)/phpunit $(ARGS)

phpstan: ## Static analysis at level 10
	$(PHP) $(BIN)/phpstan analyse --memory-limit=1G

phpcs: ## Coding standards check (PSR-12 + Slevomat)
	$(PHP) $(BIN)/phpcs

phpcs-fix: ## Auto-fix coding standards (phpcbf)
	$(PHP) $(BIN)/phpcbf

lint: ## Syntax-check every PHP file in src/migrations/tests
	@find src migrations tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null

gate: test phpstan phpcs ## Full quality gate: PHPUnit + PHPStan + phpcs

# --- Frontend -----------------------------------------------------------------

fixture: ## Regenerate tests/frontend/fixture-data.json
	$(PHP) $(FRONTEND)/generate-fixture.php

test-frontend: ## Node unit tests (markdown renderer, mean/median toggle)
	npm run test:frontend

test-smoke: fixture ## Playwright end-to-end against the fixture (regenerates it first)
	$(PLAYWRIGHT_ENV) npm run test:smoke

check: gate test-frontend test-smoke ## Everything: backend gate + frontend

# --- Database / migrations ----------------------------------------------------

migrate: ## Apply pending Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migrate-status: ## Show migration status
	$(CONSOLE) doctrine:migrations:status

migrate-diff: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

db-reset: ## Wipe the local SQLite file and re-migrate (destructive)
	rm -f var/dashboard.sqlite var/dashboard.sqlite-wal var/dashboard.sqlite-shm
	$(MAKE) migrate

cache-clear: ## Clear the Symfony cache
	$(CONSOLE) cache:clear

# --- Sync ----------------------------------------------------------------------

sync: ## Incremental sync from GitLab
	$(CONSOLE) app:sync

sync-full: ## Full backfill from GitLab
	$(CONSOLE) app:sync --full

rank-users: ## Recompute per-user all-time MR counts
	$(CONSOLE) app:rank-users

gitlab-probe: ## Test GitLab connectivity, credentials and group access
	$(CONSOLE) app:gitlab:test

# --- Local server --------------------------------------------------------------

serve: ## PHP built-in server at http://127.0.0.1:8000
	$(PHP) -S 127.0.0.1:8000 -t public public/index.php

# --- Docker (production compose + Traefik override) -----------------------------

docker-build: ## Build the production image
	$(DOCKER_COMPOSE) build

docker-up: ## Start the production stack
	$(DOCKER_COMPOSE) up -d

docker-down: ## Stop the production stack (keeps the data volume)
	$(DOCKER_COMPOSE) down

docker-down-clean: ## Stop and delete the data volume (destructive)
	$(DOCKER_COMPOSE) down -v

docker-logs: ## Tail the container logs
	$(DOCKER_COMPOSE) logs -f

docker-exec: ## Open a shell in the running container
	$(DOCKER_COMPOSE) exec cr-dashboard sh

docker-migrate: ## Run migrations inside the container
	$(DOCKER_COMPOSE) exec cr-dashboard php /app/bin/console doctrine:migrations:migrate --no-interaction

docker-sync: ## Incremental sync inside the container
	$(DOCKER_COMPOSE) exec cr-dashboard php /app/bin/console app:sync

docker-sync-full: ## Full backfill inside the container
	$(DOCKER_COMPOSE) exec cr-dashboard php /app/bin/console app:sync --full

docker-rank-users: ## Recompute rank counts inside the container
	$(DOCKER_COMPOSE) exec cr-dashboard php /app/bin/console app:rank-users
