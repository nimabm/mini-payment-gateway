.DEFAULT_GOAL := help
COMPOSE := docker compose
EXEC := $(COMPOSE) exec -T app

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.PHONY: up
up: .env ## Start everything (builds on first run, migrates and seeds itself)
	$(COMPOSE) up -d --build
	@echo ""
	@echo "  Panel -> http://localhost:$${HTTP_PORT:-8080}/admin"
	@echo "  Sign-in details are in the logs on first run: make logs"

.PHONY: down
down: ## Stop everything
	$(COMPOSE) down

.PHONY: logs
logs: ## Follow the logs
	$(COMPOSE) logs -f

.PHONY: sh
sh: ## Shell inside the container
	$(COMPOSE) exec app bash

.PHONY: test
test: ## Run the test suite
	$(EXEC) vendor/bin/phpunit

.PHONY: check
check: ## Tests, static analysis and coding standards
	$(EXEC) vendor/bin/phpunit
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=512M --no-progress
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: admin
admin: ## Create an administrator: make admin EMAIL=you@example.com
	$(EXEC) php bin/console admin:create $(EMAIL)

.PHONY: key
key: ## Print a fresh APP_KEY
	@openssl rand -base64 32

.PHONY: reset
reset: ## Stop and delete the database. Destroys every payment.
	$(COMPOSE) down -v

# Creating .env is a prerequisite of `up`, so a first run needs no separate
# step: the key is generated once and then left alone forever, because it is
# what decrypts your stored gateway credentials.
.env:
	@cp .env.example .env
	@KEY=$$(openssl rand -base64 32); \
		sed -i.bak "s|^APP_KEY=.*|APP_KEY=$$KEY|" .env && rm -f .env.bak
	@echo "Created .env with a fresh APP_KEY. Back that key up."
