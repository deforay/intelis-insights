.PHONY: up down build rebuild logs status shell db-shell db-import rag-refresh rag-reset prod clean clean-data help

.DEFAULT_GOAL := help

# ── Lifecycle ─────────────────────────────────────────────────

up: .env ## Start all services
	docker compose up -d
	@echo ""
	@echo "Intelis Insights is starting..."
	@echo "  App:          http://localhost:8080"
	@echo "  RAG API:      http://localhost:8089/health"
	@echo "  Qdrant:       http://localhost:6333/dashboard"
	@echo "  LLM Sidecar:  http://localhost:3100/health"
	@echo ""
	@echo "Run 'make logs' to follow service logs."
	@echo "Run 'make status' to check service health."

down: ## Stop all services
	docker compose down

build: ## Rebuild all images (no cache)
	docker compose build --no-cache

rebuild: ## Rebuild and restart
	docker compose up -d --build

prod: .env ## Start in production mode
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# ── Observability ─────────────────────────────────────────────

logs: ## Follow logs for all services
	docker compose logs -f

logs-%: ## Follow logs for a specific service (e.g., make logs-app)
	docker compose logs -f $*

status: ## Show service status and health
	@docker compose ps
	@echo ""
	@echo "=== Health Checks ==="
	@curl -sf http://localhost:8080/ >/dev/null 2>&1 && echo "App:          OK" || echo "App:          not ready"
	@curl -sf http://localhost:8089/health >/dev/null 2>&1 && echo "RAG API:      OK" || echo "RAG API:      not ready"
	@curl -sf http://localhost:3100/health >/dev/null 2>&1 && echo "LLM Sidecar:  OK" || echo "LLM Sidecar:  not ready"
	@curl -sf http://localhost:6333/healthz >/dev/null 2>&1 && echo "Qdrant:       OK" || echo "Qdrant:       not ready"

# ── Shells ────────────────────────────────────────────────────

shell: ## Open a shell in the PHP app container
	docker compose exec app bash

db-shell: ## Open a MySQL shell
	docker compose exec mysql mysql -u root -p"$${DB_PASSWORD:-intelis_dev}" "$${DB_NAME:-intelis_insights}"

# ── Database ──────────────────────────────────────────────────

db-import: ## Import SQL file (usage: make db-import FILE=path/to/dump.sql)
ifndef FILE
	$(error Usage: make db-import FILE=path/to/vlsm-dump.sql)
endif
	docker compose exec -T mysql mysql -u root -p"$${DB_PASSWORD:-intelis_dev}" < $(FILE)
	@echo "Import complete. Run 'make rag-refresh' to update the RAG index."

# ── RAG ───────────────────────────────────────────────────────

rag-refresh: ## Rebuild and re-seed RAG index
	docker compose exec app bash -c 'RAG_BASE_URL=http://rag-api:8089 bash /var/www/bin/rag-refresh.sh'

rag-reset: ## Reset RAG collection and re-seed from scratch
	docker compose exec app bash -c 'RAG_BASE_URL=http://rag-api:8089 bash /var/www/bin/rag-refresh.sh --reset'

# ── Setup ─────────────────────────────────────────────────────

.env: .env.example
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		echo "Created .env from .env.example"; \
		echo "Edit .env to add at least one LLM API key (ANTHROPIC_API_KEY, etc.)"; \
	fi

init-submodules: ## Initialize git submodules (llm-sidecar)
	git submodule update --init --recursive

# ── Cleanup ───────────────────────────────────────────────────

clean: ## Remove containers, volumes, and local images
	docker compose down -v --rmi local
	@echo "Cleaned up containers, volumes, and local images."

clean-data: ## Remove all persistent data (DB, Qdrant, fastembed)
	docker compose down -v
	@echo "Cleaned up all persistent data."

# ── Help ──────────────────────────────────────────────────────

help: ## Show this help
	@grep -E '^[a-zA-Z_%-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'
