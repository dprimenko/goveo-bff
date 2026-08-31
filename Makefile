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

##@ Stripe

# El CLI corre en el host, no en el contenedor: es quien hace de puente entre
# Stripe y tu localhost, y necesita su propia sesión con la cuenta.

.PHONY: stripe-login
stripe-login: ## Autorizar el CLI de Stripe (abre el navegador, una sola vez)
	stripe login

.PHONY: stripe-listen
stripe-listen: ## Reenviar los webhooks de Stripe al BFF (déjalo corriendo)
	@echo "Copia el whsec_... que imprime abajo en STRIPE_WEBHOOK_SECRET de .env.local"
	@echo "y reinicia el contenedor con 'make restart-php'."
	stripe listen --forward-to localhost:8080/api/v1/webhooks/stripe \
		--events checkout.session.completed,customer.subscription.updated,customer.subscription.deleted

.PHONY: stripe-trigger
stripe-trigger: ## Disparar un evento de prueba. Uso: make stripe-trigger EVENT=checkout.session.completed
	stripe trigger $(or $(EVENT),checkout.session.completed)

.PHONY: restart-php
restart-php: ## Recrear sólo el contenedor de PHP (recoge cambios en .env.local)
	docker compose up -d php

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

## compose-diff: enseña en qué se separan demo y producción
# Demo sólo sirve para anticipar problemas de producción si se le parece. Esto
# hace visible la diferencia, para que sea una decisión y no un despiste.
# `make` usa /bin/sh, que no tiene sustitución de procesos: de ahí los temporales.
compose-diff:
	@grep -v '^[[:space:]]*#' docker-compose.prod.yml | grep -v '^[[:space:]]*$$' > /tmp/.goveo-prod.yml
	@grep -v '^[[:space:]]*#' docker-compose.demo.yml | grep -v '^[[:space:]]*$$' > /tmp/.goveo-demo.yml
	@diff /tmp/.goveo-prod.yml /tmp/.goveo-demo.yml && echo "Sin diferencias." || true
	@rm -f /tmp/.goveo-prod.yml /tmp/.goveo-demo.yml

## db-prod: abre Adminer de producción por túnel SSH
db-prod:
	@./bin/goveo-db open prod

## db-demo: abre Adminer de demo por túnel SSH
db-demo:
	@./bin/goveo-db open demo

## db-close: cierra los túneles abiertos
db-close:
	@./bin/goveo-db close prod || true
	@./bin/goveo-db close demo || true

## db-status: qué túneles hay abiertos
db-status:
	@./bin/goveo-db status

## install-cli: deja goveo-db disponible en el PATH
install-cli:
	@ln -sf "$(CURDIR)/bin/goveo-db" /usr/local/bin/goveo-db
	@echo "goveo-db → $(CURDIR)/bin/goveo-db"
