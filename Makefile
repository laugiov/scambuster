# ======================================================================
#  Makefile – Scambuster : outillage dev / test Symfony + PostgreSQL
# ======================================================================

# ----------------------------------------------------------------------
#  Paramètres surchargeables
# ----------------------------------------------------------------------
-include .env.makefile.local

DC               = docker compose
PHP_CONTAINER_DEV   ?= backend-dev
PHP_CONTAINER_TEST  ?= backend-test
PHP_CONTAINER_E2E ?= backend-e2e
FRONT_CONTAINER  ?= frontend

# ----------------------------------------------------------------------
#  Raccourcis commandes
# ----------------------------------------------------------------------
COMPOSER_DEV      = $(DC) run --rm $(PHP_CONTAINER_DEV) composer
COMPOSER_TEST     = $(DC) run --rm $(PHP_CONTAINER_TEST) composer

CONSOLE_DEV       = $(DC) exec $(PHP_CONTAINER_DEV)  php bin/console
CONSOLE_TEST      = $(DC) exec $(PHP_CONTAINER_TEST) php bin/console
CONSOLE_E2E       = $(DC) exec $(PHP_CONTAINER_E2E) php bin/console


q ?=

# ----------------------------------------------------------------------
#  Aide
# ----------------------------------------------------------------------
HELP_FUN = \
	%help; \
	while(<>) { push @{$$help{$$2 // 'target'}}, [$$1, $$3] if /^(.*)\s*:.*\#\#(?:@(\w+))?\s(.*)$$/ }; \
	print "Usage: make [target] [option]\n\n"; \
	print "For more commands see README.md\n\n"; \
	for (keys %help) { \
		print "$$_:\n"; $$sep = " " x (24 - length ("-> $$_->[0]")); \
		print "-> $$_->[0]$$sep$$_->[1]\n" for @{$$help{$$_}}; \
		print "\n"; \
	}

help: ##@Command Show help
	@perl -e '$(HELP_FUN)' $(MAKEFILE_LIST)

# ======================================================================
#  DOCKER
# ======================================================================
up:          ##@docker Start the stack in foreground
	$(DC) up

upd:         ##@docker Start the stack in background
	$(DC) up -d

down:        ##@docker Stop and remove containers
	$(DC) down

ps:          ##@docker Show container status
	$(DC) ps

build:       ##@docker Build images
	$(DC) build

log-backend: ##@docker Backend Symfony logs
	$(DC) logs $(PHP_CONTAINER_DEV) -f

log-db:      ##@docker Postgres logs
	$(DC) logs postgres -f

# ======================================================================
#  COMPOSER
# ======================================================================
composer:              ##@composer Run Composer in dev (ex: make composer q="update")
	$(COMPOSER_DEV) $(q)

composer-test:         ##@composer Run Composer in test container
	$(COMPOSER_TEST) $(q)

composer-install:      ##@composer Install dependencies (dev)
	$(COMPOSER_DEV) install

composer-update:       ##@composer Update dependencies (dev)
	$(COMPOSER_DEV) update $(q)

composer-require:      ##@composer Add a package (dev)
	$(COMPOSER_DEV) require $(q)

composer-require-dev:  ##@composer Add a dev package (dev)
	$(COMPOSER_DEV) require --dev $(q)

composer-remove:       ##@composer Remove a package (dev)
	$(COMPOSER_DEV) remove $(q)

# ======================================================================
#  SYMFONY UTIL
# ======================================================================
cc:           ##@symfony Clear cache (dev)
	$(CONSOLE_DEV) cache:clear

cc-test:      ##@symfony Clear cache (test)
	$(CONSOLE_TEST) cache:clear --env=test

console:      ##@symfony Run Symfony console in dev (ex: make console q="debug:router")
	$(CONSOLE_DEV) $(q)

console-test: ##@symfony Run Symfony console in test
	$(CONSOLE_TEST) $(q)

debug-router: ##@symfony Show routes
	$(CONSOLE_DEV) debug:router

# ======================================================================
#  MIGRATIONS – DEV
# ======================================================================
migration:             ##@migration Run migrations (dev)
	$(CONSOLE_DEV) doctrine:migrations:migrate -n

migration-diff:        ##@migration Generate diff migration (dev)
	$(CONSOLE_DEV) doctrine:migrations:diff

migration-generate:    ##@migration Generate Maker migration (dev)
	$(CONSOLE_DEV) make:migration

create-database:       ##@migration Create database (dev)
	$(CONSOLE_DEV) doctrine:database:create --if-not-exists

reset-db:              ##@migration Drop + create + migrate (dev)
	$(CONSOLE_DEV) doctrine:database:drop --force --if-exists
	$(CONSOLE_DEV) doctrine:database:create --if-not-exists
	$(CONSOLE_DEV) doctrine:migrations:migrate -n

schema-create:         ##@migration Create schema without migrations (dev)
	$(CONSOLE_DEV) doctrine:schema:create -n

# ======================================================================
#  MIGRATIONS – TEST
# ======================================================================
migration-test:          ##@migration Run migrations (test)
	$(CONSOLE_TEST) doctrine:migrations:migrate --env=test -n

migration-generate-test: ##@migration Generate Maker migration (test)
	$(CONSOLE_TEST) make:migration --env=test

create-database-test:    ##@migration Create test database
	$(CONSOLE_TEST) doctrine:database:create --env=test --if-not-exists

reset-db-test:           ##@migration Drop + create + migrate (test)
	$(CONSOLE_TEST) doctrine:database:drop --env=test --force --if-exists
	$(CONSOLE_TEST) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE_TEST) doctrine:migrations:migrate --env=test -n

schema-create-test:      ##@migration Create schema without migrations (test)
	$(CONSOLE_TEST) doctrine:schema:create --env=test -n

# ======================================================================
#  GLOBAL DB UTIL
# ======================================================================
clean-db-complete: ##@migration Destroy & recreate dev + test DBs from scratch
	# >> DEV
	$(DC) exec postgres psql -U postgres -c "DROP DATABASE IF EXISTS scambuster;"
	$(DC) exec postgres psql -U postgres -c "CREATE DATABASE scambuster;"
	$(DC) exec postgres psql -U postgres -d scambuster -c "DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;"
	# >> TEST
	$(DC) exec postgres psql -U postgres -c "DROP DATABASE IF EXISTS scambuster_test;"
	$(DC) exec postgres psql -U postgres -c "CREATE DATABASE scambuster_test;"
	$(DC) exec postgres psql -U postgres -d scambuster_test -c "DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;"
	# recreate schema & migrations
	$(CONSOLE_DEV) doctrine:schema:create -n
	$(CONSOLE_TEST) doctrine:schema:create --env=test -n
	$(CONSOLE_DEV) make:migration
	$(CONSOLE_DEV) doctrine:migrations:migrate -n
	$(CONSOLE_TEST) doctrine:migrations:migrate --env=test -n

# ======================================================================
#  TESTS / QUALITY
# ======================================================================
test:          ##@test Load fixtures then run only integration and unit PHPUnit tests
	$(MAKE) fixtures
	$(DC) exec $(PHP_CONTAINER_TEST) vendor/bin/phpunit --testdox --testsuite integration,unit

stan:       ##@test Run PHPStan static analysis
	$(DC) exec $(PHP_CONTAINER_DEV) vendor/bin/phpstan analyse src

cs-fixer:        ##@test Check & fix code style
	$(DC) exec $(PHP_CONTAINER_DEV) vendor/bin/php-cs-fixer fix --diff --using-cache no

coverage:      ##@test Generate code coverage report
	$(DC) exec $(PHP_CONTAINER_DEV) phpdbg -qrr vendor/bin/phpunit --coverage-text > backend-symfony/coverage.txt

# ======================================================================
#  FIXTURES
# ======================================================================
fixtures:      ##@fixtures Load Doctrine fixtures
	$(CONSOLE_TEST) doctrine:fixtures:load --no-interaction --env=test


# ======================================================================
#  MIGRATIONS – E2E
# ======================================================================
migration-e2e: ##@migration Run migrations (e2e)
	$(CONSOLE_E2E) doctrine:migrations:migrate --env=e2e -n

create-database-e2e: ##@migration Create e2e database
	$(CONSOLE_E2E) doctrine:database:create --env=e2e --if-not-exists

reset-db-e2e: ##@migration Drop + create + migrate (e2e)
	$(CONSOLE_E2E) doctrine:database:drop --env=e2e --force --if-exists
	$(CONSOLE_E2E) doctrine:database:create --env=e2e --if-not-exists
	$(CONSOLE_E2E) doctrine:migrations:migrate --env=e2e -n

schema-create-e2e: ##@migration Create schema without migrations (e2e)
	$(CONSOLE_E2E) doctrine:schema:create --env=e2e -n

# ======================================================================
#  FIXTURES – E2E
# ======================================================================
fixtures-e2e: ##@fixtures Load Doctrine fixtures in E2E env
	$(CONSOLE_E2E) doctrine:fixtures:load --no-interaction --env=e2e


# ======================================================================
#  FIXTURES – DEV
# ======================================================================
fixtures-dev: ##@fixtures Load Doctrine fixtures in DEV env
	$(CONSOLE_DEV) doctrine:fixtures:load --no-interaction --env=dev

# ======================================================================
#  SCAMBAITING OPERATIONS
# ======================================================================
close-stale: ##@scambaiting Close stale conversations (default: 7 days, use d= to override)
	$(CONSOLE_DEV) app:close-stale-conversations $(if $(d),--days=$(d),)

demo-load: ##@demo Load demo dataset (150 conversations, all screens populated, no API key needed)
	cp scambuster-dataset-sample.json backend-symfony/scambuster-dataset-sample.json
	$(CONSOLE_DEV) scambuster:demo:load --purge
	rm -f backend-symfony/scambuster-dataset-sample.json

misp-test: ##@misp Test MISP connection
	$(CONSOLE_DEV) scambuster:misp:test

close-stale-dry: ##@scambaiting Preview stale conversations without closing
	$(CONSOLE_DEV) app:close-stale-conversations --dry-run $(if $(d),--days=$(d),)

bandit-report: ##@scambaiting Run bandit daily convergence report
	$(CONSOLE_DEV) app:bandit:daily-report

cleanup-weekly: ##@scambaiting Run weekly cleanup (soft-delete old conversations, purge LLM usage)
	$(CONSOLE_DEV) app:cleanup:weekly

cleanup-weekly-dry: ##@scambaiting Preview weekly cleanup without changes
	$(CONSOLE_DEV) app:cleanup:weekly --dry-run

# ======================================================================
#  DEPLOYMENT
# ======================================================================
validate: ##@docker Validate installation (check all services)
	bash scripts/validate-install.sh

validate-n8n: ##@docker Validate n8n workflow JSON files (no hardcoded values)
	bash scripts/validate-n8n-workflows.sh

doctor: ##@docker Check environment, connectivity, and n8n workflow status
	bash scripts/doctor.sh

quickstart: ##@docker Full first-time setup: build, start, DB, fixtures, JWT, n8n
	@echo ""
	@echo "╔══════════════════════════════════════════════╗"
	@echo "║       ScamBuster — Quickstart                ║"
	@echo "╚══════════════════════════════════════════════╝"
	@echo ""
	@echo "Step 1/6: Cleaning previous state..."
	@$(DC) down -v 2>/dev/null || true
	@echo ""
	@echo "Step 2/6: Preparing directories (permissions)..."
	@mkdir -p backend-symfony/vendor backend-symfony/var backend-symfony/var/cache backend-symfony/var/log backend-symfony/config/jwt backend-symfony/public/bundles
	@chmod -R 777 backend-symfony/vendor backend-symfony/var backend-symfony/config/jwt backend-symfony/public/bundles 2>/dev/null || true
	@echo ""
	@echo "Step 3/6: Building, starting, installing dependencies, DB setup..."
	$(DC) up -d --build
	$(MAKE) wait-healthy
	$(DC) exec backend-dev composer install --no-interaction --no-progress
	$(MAKE) reset-db
	$(MAKE) fixtures-dev
	@echo ""
	@echo "Step 4/6: Loading demo dataset (150 conversations, all screens)..."
	$(MAKE) demo-load
	@echo ""
	@echo "Step 5/6: Generating JWT keys and restarting backend..."
	@chmod -f 777 backend-symfony/config/jwt/*.pem 2>/dev/null || true
	@$(DC) exec backend-dev sh -c ' \
		mkdir -p /app/config/jwt && \
		openssl genpkey -algorithm RSA -out /app/config/jwt/private.pem \
			-aes-256-cbc -pass "pass:$$JWT_PASSPHRASE" -pkeyopt rsa_keygen_bits:2048 2>/dev/null && \
		openssl rsa -in /app/config/jwt/private.pem -pubout -out /app/config/jwt/public.pem \
			-passin "pass:$$JWT_PASSPHRASE" 2>/dev/null && \
		php bin/console cache:clear --no-warmup -q && \
		echo "  JWT keys generated + cache cleared."'
	@$(DC) restart backend-dev > /dev/null 2>&1
	@echo "  Backend restarted."
	@echo ""
	@echo "Step 6/6: Configuring n8n (admin account, workflows, credentials)..."
	$(DC) up -d --force-recreate n8n
	@echo "  Waiting for n8n init to complete (this takes ~30s)..."
	@sleep 30
	@$(DC) logs n8n 2>/dev/null | grep "n8n-init" | tail -10
	@echo ""
	@echo "╔══════════════════════════════════════════════╗"
	@echo "║       ScamBuster is ready!                    ║"
	@echo "╚══════════════════════════════════════════════╝"
	@echo ""
	@echo "Interfaces:"
	@echo "  Dashboard:  http://localhost:3002"
	@echo "  Backend:    http://localhost:8081/api/doc"
	@echo "  n8n:        http://localhost:5678"
	@echo "    Login:    $${N8N_DEFAULT_USER_EMAIL:-admin@scambuster.local} / (see .env)"
	@echo ""
	@echo "Verify:  make doctor"
	@echo ""
	@echo "Send a test email to your honeypot address to start!"
	@echo ""

wait-healthy: ##@docker Wait for PostgreSQL and Redis to be healthy
	@echo "Waiting for PostgreSQL..."
	@until $(DC) exec postgres pg_isready -U postgres > /dev/null 2>&1; do sleep 1; done
	@echo "Waiting for Redis..."
	@until $(DC) exec redis redis-cli ping > /dev/null 2>&1; do sleep 1; done
	@echo "All services healthy."

deploy: ##@docker Full deployment: build, start, migrate
	$(DC) up -d --build
	$(MAKE) wait-healthy
	$(MAKE) migration
	@echo ""
	@echo "ScamBuster deployed and ready."
	@echo "To configure n8n workflows, open http://localhost:5678"

# ======================================================================
#  TESTS – E2E
# ======================================================================
endToEndTest:  ##@test Load fixtures then run end-to-end tests
	$(MAKE) fixtures-e2e
	$(DC) exec $(PHP_CONTAINER_E2E) php bin/console cache:clear --env=e2e
	$(DC) run --rm $(PHP_CONTAINER_E2E) vendor/bin/phpunit --testdox --testsuite endtoend

endToEndTestOne: ##@test Run a single E2E test (q=filter)
	$(MAKE) fixtures-e2e
	$(DC) run --rm $(PHP_CONTAINER_E2E) vendor/bin/phpunit --testdox --testsuite endtoend --filter $(q)

testOne: ##@test Run a single integration/unit test (q=filter)
	$(MAKE) fixtures
	$(DC) exec $(PHP_CONTAINER_TEST) vendor/bin/phpunit --testdox --testsuite integration,unit --filter $(q)

# ======================================================================
#  DÉCLARATION .PHONY
# ======================================================================
.PHONY: help \
        up upd down ps build log-backend log-db \
        composer composer-test composer-install composer-update composer-require composer-require-dev composer-remove \
        cc cc-test console console-test debug-router \
        migration migration-diff migration-generate create-database reset-db schema-create \
        migration-test migration-generate-test create-database-test reset-db-test schema-create-test \
        clean-db-complete \
        test stan cs-fixer coverage fixtures \
        migration-e2e create-database-e2e reset-db-e2e schema-create-e2e \
        fixtures-e2e fixtures-dev \
        endToEndTest endToEndTestOne testOne \
        respawn-all


# ======================================================================
#  FRONTEND
# ======================================================================
front-test: ##@test Run frontend unit tests (Vitest)
	$(DC) exec $(FRONT_CONTAINER) npm run test

front-typecheck: ##@test Run frontend TypeScript strict check
	$(DC) exec $(FRONT_CONTAINER) npx tsc --noEmit

front-build: ##@test Run frontend production build
	$(DC) exec $(FRONT_CONTAINER) npm run build

front-lint: ##@test Run frontend ESLint
	$(DC) exec $(FRONT_CONTAINER) npm run lint

front-check: front-typecheck front-test front-build ##@test Run all frontend checks (typecheck + tests + build)

# ======================================================================
#  EVALUATION BENCHMARK SUITE
# ======================================================================
evaluate-corpus: ##@evaluate Generate evaluation corpus (use COUNT=500, DRY_RUN=1 for dry run)
	$(CONSOLE_DEV) app:evaluate:generate-corpus --count=$(or $(COUNT),500) --sleep=1.0 $(if $(DRY_RUN),--dry-run,)

evaluate-quality: ##@evaluate Evaluate reply quality from latest corpus (use CORPUS=path)
	$(CONSOLE_DEV) app:evaluate:reply-quality $(or $(CORPUS),$(shell ls -t var/evaluation/corpus-*.json 2>/dev/null | head -1))

evaluate-bandit: ##@evaluate Analyze bandit convergence
	$(CONSOLE_DEV) app:evaluate:bandit-analysis

evaluate-all: evaluate-corpus evaluate-quality evaluate-bandit ##@evaluate Run full evaluation pipeline (corpus + quality + bandit)

# ======================================================================
#  RESET ALL
# ======================================================================
respawn-all: ##@docker Reset all DBs, load fixtures and seed Vault
	$(MAKE) upd
	$(MAKE) reset-db
	$(MAKE) reset-db-test
	$(MAKE) reset-db-e2e
	$(MAKE) fixtures-dev
	$(MAKE) fixtures
	$(MAKE) fixtures-e2e
	$(CONSOLE_DEV) vault:imap-secret:add dummyhash user@example.com motdepasse123