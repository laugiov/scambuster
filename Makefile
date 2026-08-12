# ======================================================================
#  Makefile – Scambuster: dev / test tooling for Symfony + PostgreSQL
# ======================================================================

# ----------------------------------------------------------------------
#  Overridable parameters
# ----------------------------------------------------------------------
-include .env.makefile.local

DC               = docker compose
# Self-contained demo stack runs under its OWN compose project so it can never
# recreate the shared dev/prod postgres/redis or clobber the dev frontend image
# (docker-compose.demo.yml reuses the service names postgres/redis/frontend).
DC_DEMO          = docker compose -p scambuster-demo -f docker-compose.demo.yml
PHP_CONTAINER_DEV   ?= backend-dev
PHP_CONTAINER_TEST  ?= backend-test
PHP_CONTAINER_E2E ?= backend-e2e
FRONT_CONTAINER  ?= frontend

# ----------------------------------------------------------------------
#  Command shortcuts
# ----------------------------------------------------------------------
COMPOSER_DEV      = $(DC) run --rm $(PHP_CONTAINER_DEV) composer
COMPOSER_TEST     = $(DC) run --rm $(PHP_CONTAINER_TEST) composer

CONSOLE_DEV       = $(DC) exec $(PHP_CONTAINER_DEV)  php bin/console
CONSOLE_TEST      = $(DC) exec $(PHP_CONTAINER_TEST) php bin/console
CONSOLE_E2E       = $(DC) exec $(PHP_CONTAINER_E2E) php bin/console


q ?=

# ----------------------------------------------------------------------
#  Help
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
# Start the test/e2e container if it isn't running (it sits behind the `test`
# Compose profile, so quickstart doesn't start it), then make sure its database
# exists and is migrated: quickstart only provisions the dev database, so on a
# fresh install `make test` used to fail with "database scambuster_test does
# not exist". Naming the service auto-activates its profile.
test-prepare:  ##@test Start backend-test and ensure the test DB exists (create + migrate)
	$(DC) up -d $(PHP_CONTAINER_TEST)
	$(CONSOLE_TEST) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE_TEST) doctrine:migrations:migrate --env=test -n --allow-no-migration

e2e-prepare:   ##@test Start backend-e2e and ensure the e2e DB exists (create + migrate)
	$(DC) up -d $(PHP_CONTAINER_E2E)
	$(CONSOLE_E2E) doctrine:database:create --if-not-exists --env=e2e
	$(CONSOLE_E2E) doctrine:migrations:migrate --env=e2e -n --allow-no-migration

test:          ##@test Prepare the test DB, load fixtures, run unit+integration+functional
	$(MAKE) test-prepare
	$(MAKE) fixtures
	$(DC) exec $(PHP_CONTAINER_TEST) vendor/bin/phpunit --testdox --testsuite integration,functional,unit

stan:       ##@test Run PHPStan static analysis
	$(DC) exec $(PHP_CONTAINER_DEV) vendor/bin/phpstan analyse src --memory-limit=512M

cs-fixer:        ##@test Check & fix code style
	$(DC) exec $(PHP_CONTAINER_DEV) vendor/bin/php-cs-fixer fix --diff --using-cache no

coverage:      ##@test Generate code coverage report
	$(DC) exec $(PHP_CONTAINER_DEV) phpdbg -qrr vendor/bin/phpunit --coverage-text > backend-symfony/coverage.txt

mutation:      ##@test Run Infection mutation testing (unit tests only)
	$(DC) exec $(PHP_CONTAINER_DEV) vendor/bin/infection --threads=4 --show-mutations --min-msi=70 --min-covered-msi=80

smoke-reply-objective:    ##@test Smoke harness — drive reply pipeline on .eml fixtures (~$0.05 in real LLM calls)
	@chmod -R 777 backend-symfony/var/smoke 2>/dev/null || mkdir -p backend-symfony/var/smoke && chmod -R 777 backend-symfony/var/smoke
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:smoke:reply-objective

smoke-cialdini-mirror:    ##@test Smoke harness — drive reply pipeline + capture Cialdini mirror detection (~$0.15 in real LLM calls)
	@chmod -R 777 backend-symfony/var/smoke 2>/dev/null || mkdir -p backend-symfony/var/smoke && chmod -R 777 backend-symfony/var/smoke
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:smoke:cialdini-mirror

smoke-spec-140:    ##@test Smoke harness — replay the 4 fallback-storm shapes against the real pipeline (~$0.10 in real LLM calls, drafts only)
	@chmod -R 777 backend-symfony/var/smoke 2>/dev/null || mkdir -p backend-symfony/var/smoke && chmod -R 777 backend-symfony/var/smoke
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:smoke:reply-objective --fixtures-dir tests/Smoke/Spec140Fixtures --output-dir var/smoke/spec-140

guard-baseline:    ##@test Regenerate the GUARD canary baseline from a real-LLM run over the 73 smoke fixtures (~$0.14, ~35min). Review + commit the diff deliberately.
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:smoke:reply-objective --summary-json=var/smoke/guard-summary.json
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:guard:baseline --summary-json=var/smoke/guard-summary.json --out=var/smoke/guard-baseline.json
	cp backend-symfony/var/smoke/guard-baseline.json backend-symfony/tests/Smoke/guard-baseline.json
	cp backend-symfony/var/smoke/guard-baseline.json.sha256 backend-symfony/tests/Smoke/guard-baseline.json.sha256
	@echo "GUARD baseline refreshed in backend-symfony/tests/Smoke/ — review the diff and commit it deliberately."

guard:    ##@test GUARD merge-gate — real-LLM smoke over the 73 fixtures, then diff the candidate vs the frozen baseline; exits non-zero on any regression (~$0.14, ~35min).
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:smoke:reply-objective --summary-json=var/smoke/guard-candidate.json
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console scambuster:guard:check --summary-json=var/smoke/guard-candidate.json --baseline=tests/Smoke/guard-baseline.json

guard-canary-work:    ##@test Drain ONE pending prompt-validation job on demand (alternative to the canary-worker service) — real-LLM smoke of the candidate, ~35min.
	$(CONSOLE_DEV) scambuster:guard:canary:work

guard-hook-install:    ##@test Install the opt-in GUARD pre-push hook (warns, or blocks with GUARD_ON_PUSH=1, when a push changes prompt files).
	@mkdir -p .git/hooks
	@if [ -e .git/hooks/pre-push ] && ! grep -q "GUARD pre-push hook" .git/hooks/pre-push 2>/dev/null; then \
		echo "Refusing to overwrite an existing .git/hooks/pre-push that is not the GUARD hook."; exit 1; \
	fi
	@cp scripts/hooks/pre-push-guard.sh .git/hooks/pre-push
	@chmod +x .git/hooks/pre-push
	@echo "GUARD pre-push hook installed. It warns by default; set GUARD_ON_PUSH=1 to enforce."

prompt-diag:    ##@config Show which operator prompt overrides + settings are active (read-only, no LLM). Pass KEY=<key> to print one.
	$(CONSOLE_DEV) scambuster:prompt:diag $(KEY)

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

demo-load: ##@demo Load demo dataset (36 conversations, all screens populated, no API key needed)
	cp scambuster-dataset-sample.json backend-symfony/scambuster-dataset-sample.json
	$(CONSOLE_DEV) scambuster:demo:load --purge
	rm -f backend-symfony/scambuster-dataset-sample.json
	$(CONSOLE_DEV) app:clustering:backfill --no-interaction
	# Same step the demo stack runs (infra/docker/demo/docker-entrypoint-demo.sh).
	# Without it the TTP Explorer, the review queue and the per-conversation
	# stimulus -> TTP -> IOC timeline stay empty on a quickstart install, while
	# the published demo shows them populated. Idempotent: skips any message
	# that already has observations, so real LLM extractions are preserved.
	$(CONSOLE_DEV) scambuster:ttp:demo-seed
	# The demo loader writes IOC observations directly, bypassing the ingest
	# path where IocExportMapper stamps the MISP/STIX export metadata. Without
	# this, every demo IOC exports with a null `misp`/`stix` block: the IOC
	# Explorer shows no export mapping, and the MISP Event export used to come
	# back with zero attributes. Idempotent — already-enriched rows are skipped.
	$(CONSOLE_DEV) app:migrate-iocs-export-metadata

demo-up: ##@demo Start self-contained demo (no API key, no config needed)
	$(DC_DEMO) up -d --build

demo-down: ##@demo Stop demo containers
	$(DC_DEMO) down

demo-reset: ##@demo Reset demo (wipe DB + rebuild)
	$(DC_DEMO) down -v
	$(DC_DEMO) up -d --build

demo-logs: ##@demo Show demo logs
	$(DC_DEMO) logs -f

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

ioc-context-backfill: ##@scambaiting Compute structural context for historical IOCs (no LLM)
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console app:ioc:compute-context --limit=500

ioc-context-dry: ##@scambaiting Preview IOC context computation (dry-run)
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console app:ioc:compute-context --dry-run

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
	@echo "Checking .env configuration..."
	@if [ ! -f .env ]; then echo "  STOP: no .env found. Run 'cp .env.dist .env', fill in your mailbox/SMTP/LLM values, then re-run."; exit 1; fi
	@MISCONFIG=0; \
	  grep -q '^HONEYPOT_IMAP_USER=your-honeypot@gmail.com' .env && MISCONFIG=1; \
	  grep -q '^HONEYPOT_IMAP_PASSWORD=your-app-password-here' .env && MISCONFIG=1; \
	  grep -q '^MAILER_DSN=null://null' .env && MISCONFIG=1; \
	  grep -q '^LLM_API_KEY=sk-your-api-key-here' .env && MISCONFIG=1; \
	  if [ "$$MISCONFIG" = "1" ]; then \
	    echo ""; \
	    echo "  WARNING: some .env values are still placeholders (mailbox / SMTP / LLM key)."; \
	    echo "  ScamBuster will install in DEMO mode. Live email capture and replies will"; \
	    echo "  NOT work until these are set, because the honeypot mailbox and the n8n IMAP"; \
	    echo "  credential are configured DURING this install, from .env."; \
	    echo "  For real use: fill .env NOW (see docs/QUICKSTART.md), then re-run 'make quickstart'."; \
	    echo ""; \
	  else \
	    echo "  OK: .env configured for real use."; \
	  fi
	@bash scripts/preflight.sh
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
	@echo "Step 4/6: Loading demo dataset (36 conversations, all screens)..."
	$(MAKE) demo-load
	@echo ""
	@echo "Step 4b/6: Applying data quality fixes (risk scores, semantic roles, cluster sophistication)..."
	$(MAKE) fix-existing-data
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
	@echo "Verifying the installation..."
	@if bash scripts/doctor.sh; then \
	  echo ""; \
	  echo "Send a test email to your honeypot address to start!"; \
	  echo ""; \
	else \
	  echo ""; \
	  echo "  The stack is installed and running, but the checks above FAILED."; \
	  echo "  Live capture, classification or replies will not work until they pass."; \
	  echo "  Fix .env, then reload it with:"; \
	  echo "    docker compose up -d --force-recreate backend-dev scheduler"; \
	  echo "  and re-check with 'make doctor'."; \
	  echo ""; \
	fi

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
endToEndTest:  ##@test Prepare the e2e DB, load fixtures, run end-to-end tests
	$(MAKE) e2e-prepare
	$(MAKE) fixtures-e2e
	$(DC) exec $(PHP_CONTAINER_E2E) php bin/console cache:clear --env=e2e
	$(DC) run --rm $(PHP_CONTAINER_E2E) vendor/bin/phpunit --testdox --testsuite endtoend

endToEndTestOne: ##@test Run a single E2E test (q=filter)
	$(MAKE) e2e-prepare
	$(MAKE) fixtures-e2e
	$(DC) run --rm $(PHP_CONTAINER_E2E) vendor/bin/phpunit --testdox --testsuite endtoend --filter $(q)

testOne: ##@test Run a single integration/unit test (q=filter)
	$(MAKE) test-prepare
	$(MAKE) fixtures
	$(DC) exec $(PHP_CONTAINER_TEST) vendor/bin/phpunit --testdox --testsuite integration,functional,unit --filter $(q)

# ======================================================================
#  .PHONY DECLARATION
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
        audit-quality audit-deep \
        ttp-audit-sample ttp-audit-score taxonomy-export f3-mapping stix-conformance dataset-labels misp-machinetag standards-check \
        fix-semantic-roles fix-risk-scores compute-sophistication \
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
#  AUDIT / GROUND TRUTH VERIFICATION
# ======================================================================
verify-iocs:          ##@audit Verify IOC source presence
	$(CONSOLE_DEV) app:verify:ioc-source-presence

verify-clusters:      ##@audit Verify cluster anchor quality
	$(CONSOLE_DEV) app:verify:cluster-quality

verify-classification: ##@audit Generate classification spot-check report
	$(CONSOLE_DEV) app:verify:classification

audit-quality:   ##@audit Run LLM quality audit on sampled conversations
	$(DC) exec $(PHP_CONTAINER_DEV) php bin/console app:audit:conversation-quality

audit-deep:      ##@audit Run complete audit suite (screening + LLM quality)
	$(MAKE) verify-iocs
	$(MAKE) verify-clusters
	$(MAKE) verify-classification
	$(MAKE) audit-quality

# ======================================================================
#  STANDARD-TRACK ARTIFACTS
#  Generated taxonomy artifact, external framework mapping and the manual
#  TTP extraction quality audit. See docs/standards/.
# ======================================================================
ttp-audit-sample: ##@standards Export a seeded TTP sample to score by hand (SEED=4242 LIMIT=100 [STRATIFIED=1])
	$(CONSOLE_DEV) scambuster:ttp:audit-sample \
	  --seed=$(or $(SEED),4242) --limit=$(or $(LIMIT),100) \
	  $(if $(STRATIFIED),--stratified,) \
	  --output=var/audit/ttp-sample.csv

ttp-audit-score: ##@standards Compute precision, agreement and kappa from a scored sheet (SHEET=path)
	$(CONSOLE_DEV) scambuster:ttp:audit-score $(or $(SHEET),var/audit/ttp-sample-scored.csv) \
	  $(if $(SEED),--seed=$(SEED),) $(if $(DRAW),--draw=$(DRAW),)

taxonomy-export: ##@standards Regenerate the machine-readable taxonomy artifact from the canonical seed
	$(CONSOLE_DEV) scambuster:ttp:taxonomy-export

f3-mapping: ##@standards Validate the MITRE F3 mapping decisions and regenerate its document table
	$(CONSOLE_DEV) scambuster:ttp:f3-mapping
	python3 scripts/standards/render-f3-mapping.py

stix-conformance: ##@standards Validate every exported STIX bundle type against the external OASIS validator
	bash scripts/standards/validate-stix-bundles.sh

dataset-labels: ##@standards Validate the dataset TTP labels (COMPLETE=1 for the release gate)
	python3 scripts/standards/validate-dataset-labels.py $(if $(COMPLETE),--complete,)

misp-machinetag: ##@standards Regenerate the MISP taxonomy file (internal only; filing is gated)
	$(CONSOLE_DEV) scambuster:ttp:misp-machinetag

standards-check: ##@standards Run every standard-track guard exactly as CI does
	$(CONSOLE_DEV) scambuster:ttp:taxonomy-export --check
	$(CONSOLE_DEV) scambuster:ttp:f3-mapping
	$(CONSOLE_DEV) scambuster:ttp:misp-machinetag --check
	python3 scripts/standards/render-f3-mapping.py --check
	python3 scripts/standards/validate-taxonomy-artifact.py
	python3 scripts/standards/check-taxonomy-versioning.py
	python3 scripts/standards/validate-dataset-labels.py
	bash scripts/standards/validate-stix-bundles.sh

# ======================================================================
#  BATCH FIX / RECOMPUTE
# ======================================================================
fix-semantic-roles: ##@fix Fix mislabeled semantic roles on IOC contexts (use DRY_RUN=1 for preview)
	$(CONSOLE_DEV) app:fix:semantic-roles $(if $(DRY_RUN),--dry-run,)

fix-risk-scores: ##@fix Recalculate risk scores for all conversations (use DRY_RUN=1, SCAM_TYPE=CHARITY)
	$(CONSOLE_DEV) app:fix:risk-scores $(if $(DRY_RUN),--dry-run,) $(if $(SCAM_TYPE),--scam-type=$(SCAM_TYPE),)

compute-sophistication: ##@fix Compute cluster sophistication levels (use DRY_RUN=1 for preview)
	$(CONSOLE_DEV) app:compute:cluster-sophistication $(if $(DRY_RUN),--dry-run,)

fix-existing-data: ##@fix Apply all data quality corrections after fixture/demo load
	@echo "  → Running migrations..."
	$(CONSOLE_DEV) doctrine:migrations:migrate --no-interaction -q 2>/dev/null || true
	@echo "  → Recalculating risk scores..."
	$(CONSOLE_DEV) app:fix:risk-scores -q 2>/dev/null || true
	@echo "  → Fixing semantic roles..."
	$(CONSOLE_DEV) app:fix:semantic-roles -q 2>/dev/null || true
	@echo "  → Computing cluster sophistication..."
	$(CONSOLE_DEV) app:compute:cluster-sophistication -q 2>/dev/null || true
	@echo "  ✓ Data quality fixes applied."

# ======================================================================
#  RESET ALL
# ======================================================================
respawn-all: ##@docker Reset all DBs and load fixtures
	$(MAKE) upd
	# backend-test / backend-e2e sit behind the `test` Compose profile, so the
	# plain `upd` above doesn't start them; name them to auto-activate it.
	$(DC) up -d $(PHP_CONTAINER_TEST) $(PHP_CONTAINER_E2E)
	$(MAKE) reset-db
	$(MAKE) reset-db-test
	$(MAKE) reset-db-e2e
	$(MAKE) fixtures-dev
	$(MAKE) fixtures
	$(MAKE) fixtures-e2e