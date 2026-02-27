# Manual Setup (Without Docker)

If you prefer running PHP and MySQL natively instead of in containers. You still need Docker for Qdrant and the RAG API (they don't have native installers).

Make sure you have everything listed in [Prerequisites](prerequisites.md#manual-setup-without-docker).

## 1. Clone & Install Dependencies

```bash
git clone --recurse-submodules https://github.com/deforay/intelis-insights.git
cd intelis-insights
composer install
```

!!! warning "Empty `llm-sidecar/` folder?"
    If you forgot `--recurse-submodules`, run:
    ```bash
    git submodule update --init --recursive
    ```

## 2. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your local values:

```bash
# Point to your local MySQL
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=intelis_insights
DB_USER=root
DB_PASSWORD=your-password

# Add at least one LLM API key
ANTHROPIC_API_KEY=sk-ant-your-key-here
```

See [Environment Variables](../guides/environment-variables.md) for the full list.

## 3. Create the App Database

The application uses its own database for reports, chat sessions, and audit logs:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS intelis_insights
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Run the schema migrations in order:

```bash
mysql -u root -p intelis_insights < database/001_create_schema.sql
mysql -u root -p intelis_insights < database/002_create_vl_aggregate_tables.sql
mysql -u root -p intelis_insights < database/003_refresh_vl_aggregates.sql
mysql -u root -p intelis_insights < database/004_system_tables.sql
mysql -u root -p intelis_insights < database/seed.sql
```

??? tip "Verify the tables were created"
    ```bash
    mysql -u root -p intelis_insights -e "SHOW TABLES;"
    ```
    You should see tables like `reports`, `chat_sessions`, `chat_messages`, `audit_logs`, etc.

## 4. Start Qdrant & RAG API (Docker)

These two services run in Docker even for manual setup:

```bash
docker compose up -d qdrant rag-api
```

Verify they're healthy:

```bash
curl http://127.0.0.1:6333/healthz       # Qdrant — should return {"title":"..."}
curl http://127.0.0.1:8089/health         # RAG API — should return {"status":"ok"}
```

!!! info "First run: model download"
    The RAG API downloads an embedding model (~200 MB) on first start. It may take up to 60 seconds before the health check passes.

## 5. Start the LLM Sidecar

```bash
cd llm-sidecar
bun install
bun run start
```

??? note "Don't have Bun?"
    Install it first:
    ```bash
    curl -fsSL https://bun.sh/install | bash
    source ~/.bashrc  # or ~/.zshrc
    ```

Verify:

```bash
curl http://127.0.0.1:3100/health         # Should return {"status":"ok"}
```

Leave this running in a terminal tab, then open a new tab for the next step.

## 6. Seed the Vector Database

If you have InteLIS data connected, seed the RAG index:

```bash
cd /path/to/intelis-insights   # back to root
bash bin/rag-refresh.sh
```

See [RAG Seeding](../guides/rag-seeding.md) for details on what this does.

## 7. Start the Application

```bash
composer start
```

This starts the PHP development server at [http://localhost:8080](http://localhost:8080).

## 8. Verify

```bash
curl http://localhost:8080/health          # Should return {"status":"ok"}
curl http://localhost:8080/status          # Should show database: "connected"
```

Open [http://localhost:8080](http://localhost:8080) in your browser.

## Next Step

Run through the [First-Run Checklist](first-run-checklist.md) to verify everything works.
