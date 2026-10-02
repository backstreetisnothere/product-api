.DEFAULT_GOAL := help

DC      := docker compose
APP     := $(DC) run --rm app
APP_TEST := $(DC) run --rm -e APP_ENV=test app

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

.PHONY: up
up: ## Build and start the stack (API on http://localhost:8080, Swagger UI on /api/doc)
	$(DC) up -d --build

.PHONY: down
down: ## Stop the stack (keeps the database volume)
	$(DC) down

.PHONY: reset
reset: ## Stop the stack and DELETE the database volume
	$(DC) down -v

.PHONY: logs
logs: ## Tail application logs
	$(DC) logs -f app

.PHONY: shell
shell: ## Open a shell in the app container
	$(DC) exec app sh

.PHONY: install
install: ## Install Composer dependencies
	$(APP) composer install

.PHONY: migrate
migrate: ## Apply database migrations
	$(APP) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: merchant
merchant: ## Create a merchant: make merchant NAME="Acme Ltd"
	$(APP) php bin/console app:merchant:create "$(NAME)"

.PHONY: seed
seed: ## Create a demo merchant with synthetic products, prices and stock
	$(APP) php bin/console app:demo:seed

.PHONY: test-db
test-db: ## Reset the test cache, create and migrate the test database
	$(APP_TEST) sh -c "php bin/console cache:clear --no-warmup && php bin/console doctrine:database:create --if-not-exists --no-interaction && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"

.PHONY: test
test: test-db ## Run the whole test suite (unit + functional, needs PostgreSQL and Redis)
	$(APP_TEST) vendor/bin/phpunit

.PHONY: test-unit
test-unit: ## Run unit tests only (no external services)
	$(APP_TEST) vendor/bin/phpunit --testsuite unit

.PHONY: cs
cs: ## Check code style
	$(APP) vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes

.PHONY: cs-fix
cs-fix: ## Fix code style
	$(APP) vendor/bin/php-cs-fixer fix --allow-risky=yes

.PHONY: stan
stan: ## Run PHPStan (level 8)
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: qa
qa: cs stan test ## Everything CI runs

.PHONY: concurrency
concurrency: ## Prove exactly-once execution and no overselling under parallel load: make concurrency KEY=sk_...
	sh scripts/concurrency-check.sh $(KEY)

.PHONY: openapi
openapi: ## Dump the OpenAPI document to openapi.json
	$(APP) php bin/console nelmio:apidoc:dump --format=json > openapi.json
