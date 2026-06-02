.DEFAULT_GOAL := help

##@ Development

.PHONY: up
up: ## Start development environment
	docker compose up -d

.PHONY: down
down: ## Stop development environment
	docker compose down

.PHONY: restart
restart: down up ## Restart development environment

.PHONY: logs
logs: ## Show PHP logs
	docker compose logs -f php

.PHONY: bash
bash: ## Open shell in PHP container
	docker compose exec php bash

.PHONY: install
install: ## Install PHP dependencies
	docker compose exec php composer install

##@ Database

.PHONY: migrate
migrate: ## Run database migrations
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: migration
migration: ## Generate a new migration based on entity changes
	docker compose exec php php bin/console doctrine:migrations:diff

.PHONY: migration-new
migration-new: ## Create a blank migration
	docker compose exec php php bin/console doctrine:migrations:generate

.PHONY: db-reset
db-reset: ## Drop and recreate the database (destructive!)
	docker compose exec php php bin/console doctrine:database:drop --force
	docker compose exec php php bin/console doctrine:database:create
	docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: schema-validate
schema-validate: ## Validate Doctrine mapping against DB schema
	docker compose exec php php bin/console doctrine:schema:validate

##@ Code Quality

.PHONY: test
test: ## Run test suite
	docker compose exec php php bin/phpunit

.PHONY: cache-clear
cache-clear: ## Clear application cache
	docker compose exec php php bin/console cache:clear

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
