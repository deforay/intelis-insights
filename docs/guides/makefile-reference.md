# Makefile Reference

The Makefile provides shortcut commands for common tasks. Run `make help` to see all available targets.

## Lifecycle

| Command | Description |
|---------|-------------|
| `make up` | Start all services (`docker compose up -d`) |
| `make down` | Stop all services |
| `make build` | Rebuild all Docker images from scratch (no cache) |
| `make rebuild` | Rebuild images and restart services |
| `make prod` | Start in production mode (uses `docker-compose.prod.yml` overlay) |

## Observability

| Command | Description |
|---------|-------------|
| `make logs` | Follow logs for all services |
| `make logs-app` | Follow logs for the PHP app only |
| `make logs-mysql` | Follow logs for MySQL only |
| `make logs-rag-api` | Follow logs for the RAG API only |
| `make logs-llm-sidecar` | Follow logs for the LLM sidecar only |
| `make status` | Show container status and run health checks |

!!! tip "Log filtering"
    `make logs-<service>` works for any service name defined in `docker-compose.yml`. Replace `<service>` with `app`, `mysql`, `qdrant`, `rag-api`, `llm-sidecar`, or `init`.

## Shells

| Command | Description |
|---------|-------------|
| `make shell` | Open a bash shell inside the PHP app container |
| `make db-shell` | Open a MySQL CLI connected to the app database |

## Database

| Command | Description |
|---------|-------------|
| `make db-import FILE=path/to/dump.sql` | Import a SQL file into the Docker MySQL container |

Example:

```bash
make db-import FILE=~/downloads/vlsm-dump.sql
```

After importing, run `make rag-refresh` to update the RAG index.

## RAG

| Command | Description |
|---------|-------------|
| `make rag-refresh` | Re-export schema, rebuild snippets, and upload to Qdrant |
| `make rag-reset` | Delete the Qdrant collection and re-seed from scratch |

See [RAG Seeding](rag-seeding.md) for details on when to use each.

## Setup

| Command | Description |
|---------|-------------|
| `make init-submodules` | Initialize git submodules (if `llm-sidecar/` is empty) |

The Makefile also automatically creates `.env` from `.env.example` if it doesn't exist when you run `make up`.

## Cleanup

| Command | Description | What it removes |
|---------|-------------|----------------|
| `make clean` | Full cleanup | Containers, volumes, and locally-built images |
| `make clean-data` | Data cleanup | Containers and volumes (database data, Qdrant data, fastembed cache) |

!!! warning "These commands delete data"
    `make clean` and `make clean-data` remove Docker volumes, which means your MySQL data, Qdrant index, and downloaded embedding model will be lost. You'll need to re-import the InteLIS database and re-seed RAG after running these.
