DC = docker compose -f docker-compose.yml -f docker-compose.override.yml
PHP = $(DC) exec app
ARTISAN = $(PHP) php artisan

.PHONY: help up down build rebuild restart logs shell \
        artisan migrate migrate-fresh seed composer \
        pint test tinker

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- Docker ---

up: ## Start all containers
	$(DC) up -d

down: ## Stop and remove containers
	$(DC) down

build: ## Build images (no cache)
	$(DC) build --no-cache

rebuild: down build up ## Full rebuild: down → build → up

restart: ## Restart all containers
	$(DC) restart

logs: ## Tail logs (use s=service to filter, e.g. make logs s=app)
	$(DC) logs -f $(s)

# --- App shell ---

shell: ## Open bash in app container
	$(PHP) bash

# --- Laravel ---

artisan: ## Run artisan command: make artisan cmd="route:list"
	$(ARTISAN) $(cmd)

migrate: ## Run migrations
	$(ARTISAN) migrate

migrate-fresh: ## Drop all tables and re-run migrations + seeders
	$(ARTISAN) migrate:fresh --seed

seed: ## Run database seeders
	$(ARTISAN) db:seed

composer: ## Run composer command: make composer cmd="require foo/bar"
	$(PHP) composer $(cmd)

pint: ## Run Laravel Pint code formatter
	$(PHP) vendor/bin/pint --dirty

test: ## Run the test suite (use f=tests/Feature/Foo to filter)
	$(ARTISAN) test $(f)

tinker: ## Open Laravel Tinker REPL
	$(ARTISAN) tinker

# --- Setup ---

env: ## Copy .env.example to .env if .env does not exist
	@test -f .env || (cp .env.example .env && echo ".env created from .env.example")

key: ## Generate application key
	$(ARTISAN) key:generate

setup: env up ## Initial setup: create .env + start containers
	@echo "Run 'make key' then 'make migrate' to finish setup."
