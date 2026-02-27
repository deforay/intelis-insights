# Troubleshooting

Common problems and how to fix them.

## Setup Issues

### `llm-sidecar/` directory is empty

**Cause:** You cloned without `--recurse-submodules`.

**Fix:**
```bash
git submodule update --init --recursive
```

Then rebuild if you've already run `make up`:
```bash
make rebuild
```

---

### `make up` fails with "no matching manifest for linux/arm64"

**Cause:** Some Docker images don't have ARM builds (common on Apple Silicon Macs).

**Fix:** Ensure you're using Docker Desktop 4.x+ which supports Rosetta emulation. If still failing, try:
```bash
DOCKER_DEFAULT_PLATFORM=linux/amd64 make up
```

---

### `.env: no such file or directory`

**Cause:** You haven't created the `.env` file.

**Fix:**
```bash
cp .env.example .env
```

Then edit `.env` to add at least one LLM API key.

## Service Health Issues

### App shows "not ready" in `make status`

**Possible causes:**

1. **MySQL not ready yet** — The app depends on MySQL. Wait 30 seconds and try again.
2. **Wrong database credentials** — Check `DB_PASSWORD` in `.env` matches what MySQL expects.
3. **Port 8080 already in use** — Another process is using port 8080.

**Debug:**
```bash
make logs-app     # check PHP app logs
docker compose ps # check container status
```

---

### RAG API shows "not ready"

**Possible causes:**

1. **First run — downloading the embedding model** — The RAG API downloads a ~200 MB model on first start. This can take up to 60 seconds. Wait and check again.
2. **Qdrant not healthy** — The RAG API depends on Qdrant. Check Qdrant first.

**Debug:**
```bash
make logs-rag-api    # check RAG API logs
curl http://localhost:6333/healthz   # check Qdrant directly
```

---

### LLM Sidecar shows "not ready"

**Possible causes:**

1. **Empty `llm-sidecar/` directory** — See [above](#llm-sidecar-directory-is-empty).
2. **Build failed** — Check the build logs.

**Debug:**
```bash
make logs-llm-sidecar
docker compose logs llm-sidecar --tail=50
```

---

### MySQL connection refused

**Possible causes:**

1. **Container still initializing** — MySQL takes up to 30 seconds on first start (runs init SQL scripts).
2. **Password mismatch** — `DB_PASSWORD` in `.env` must match `MYSQL_ROOT_PASSWORD` (they're the same variable in `docker-compose.yml`).
3. **Port conflict** — Another MySQL instance is using port 3306.

**Debug:**
```bash
make logs-mysql
docker compose exec mysql mysqladmin ping -h localhost
```

**Fix for port conflict:**

Change the port in `.env`:
```bash
DB_PORT=3307
```

Then restart: `make down && make up`. Remember to also update any host-side tools that connect on 3306.

## Port Conflicts

If a port is already in use, you'll see errors like `Bind for 0.0.0.0:8080 failed: port is already allocated`.

### Find what's using the port

```bash
# macOS
lsof -i :8080

# Linux
ss -tlnp | grep 8080
```

### Change the port

The easiest fix is to stop whatever is using the port. If you can't, you can change the mapping in `docker-compose.yml`:

```yaml
# Change the left side (host port) only
ports:
  - "9080:8080"   # now accessible at localhost:9080
```

Common ports used by Intelis Insights:

| Port | Service | Alternative |
|------|---------|-------------|
| 8080 | PHP App | Change to 9080, 8081, etc. |
| 3306 | MySQL | Change `DB_PORT` in `.env` |
| 6333 | Qdrant | Edit `docker-compose.yml` |
| 8089 | RAG API | Edit `docker-compose.yml` |
| 3100 | LLM Sidecar | Edit `docker-compose.yml` |

## Chat / Query Issues

### Chat returns "Unable to generate SQL"

**Possible causes:**

1. **No InteLIS data** — The query database is empty or not connected. See [Connecting InteLIS Data](guides/connecting-intelis-data.md).
2. **RAG index is empty** — Run `make rag-refresh` to seed the index.
3. **Question is too vague** — Try a more specific question like *"How many viral load tests were done last month?"*.

---

### Chat returns "This question asks for restricted patient-level data"

**This is intentional.** The system blocks questions that ask for individual patient data (names, IDs, phone numbers). This is a privacy safeguard. Rephrase your question to ask for aggregated data instead.

**Blocked:** *"Show me patient names with high viral load"*
**Allowed:** *"How many patients have unsuppressed viral load by district?"*

---

### LLM API key rejected / "Unauthorized"

**Possible causes:**

1. **Invalid API key** — Double-check the key in `.env`. Make sure there are no extra spaces.
2. **Wrong provider** — If you set `LLM_DEFAULT_MODEL=sonnet` but only have an OpenAI key, it won't work. Either change the model or add an Anthropic key.
3. **Expired key** — Check your provider dashboard for key status.

**Debug:**
```bash
# Test the sidecar directly
curl http://localhost:3100/v1/models \
  -H "Authorization: Bearer $LLM_SIDECAR_SECRET"
```

---

### RAG search returns irrelevant results

**Possible causes:**

1. **Stale index** — The schema or business rules changed since last seeding.
2. **Index corrupted** — Rare, but possible.

**Fix:**
```bash
make rag-reset    # full reset + re-seed
```

## Docker Issues

### "no space left on device"

Docker images and volumes can accumulate over time.

**Fix:**
```bash
# Remove unused Docker data
docker system prune -a

# Or just remove Intelis Insights data
make clean
```

---

### Container keeps restarting

**Debug:**
```bash
docker compose ps                    # check status
docker compose logs <service> --tail=100   # check logs
```

Common causes:

- **Health check failing** — The service starts but the health endpoint returns errors.
- **Missing dependency** — A service that this one depends on isn't healthy.
- **Configuration error** — Check `.env` for typos.

## Getting More Help

If you're stuck:

1. Check the logs: `make logs` or `make logs-<service>`
2. Check container status: `docker compose ps`
3. Check health endpoints individually (see [First-Run Checklist](getting-started/first-run-checklist.md))
4. [Open an issue](https://github.com/deforay/intelis-insights/issues) on GitHub
