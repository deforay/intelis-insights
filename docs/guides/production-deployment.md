# Production Deployment

This guide covers deploying Intelis Insights to a production environment.

## Using the Production Compose Override

The production configuration switches to nginx + php-fpm, disables debug mode, and hides internal service ports:

```bash
make prod
```

Or equivalently:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

## Required Environment Variables

Set these in your `.env` before deploying:

```bash
# ── Security ─────────────────────────────────
APP_ENV=production
APP_DEBUG=false
ALLOW_INSECURE_NO_AUTH=false

# ── Secrets ──────────────────────────────────
DB_PASSWORD=<strong-database-password>
LLM_SIDECAR_SECRET=<strong-random-secret>

# ── LLM Keys ────────────────────────────────
ANTHROPIC_API_KEY=sk-ant-...
# Add other provider keys as needed
```

### Generating a Sidecar Secret

The `LLM_SIDECAR_SECRET` authenticates the PHP app's requests to the LLM sidecar. Generate a random string:

```bash
openssl rand -hex 32
```

Set the same value in `.env` as `LLM_SIDECAR_SECRET`. The Docker Compose file passes it to both the app and sidecar containers.

## Security Checklist

Before going live, verify:

- [ ] `APP_DEBUG=false` — Prevents verbose error messages leaking to users
- [ ] `ALLOW_INSECURE_NO_AUTH=false` — Requires authentication on the LLM sidecar
- [ ] `LLM_SIDECAR_SECRET` is set to a strong random value
- [ ] `DB_PASSWORD` is a strong password (not the default `intelis_dev`)
- [ ] Internal ports (3306, 6333, 8089, 3100) are not exposed to the internet
- [ ] API keys are not committed to version control
- [ ] The InteLIS database user is read-only

## Port Exposure

In development, all service ports are exposed to the host for debugging. In production, only the application port should be reachable:

| Service | Development | Production |
|---------|------------|------------|
| App (nginx) | :8080 | :8080 (or behind a reverse proxy) |
| MySQL | :3306 | Internal only |
| Qdrant | :6333 | Internal only |
| RAG API | :8089 | Internal only |
| LLM Sidecar | :3100 | Internal only |

The `docker-compose.prod.yml` override removes the port mappings for internal services.

## Reverse Proxy

In production, place a reverse proxy (nginx, Caddy, etc.) in front of the application for TLS termination:

```
Internet → Reverse Proxy (HTTPS :443) → Docker App (:8080)
```

Example nginx configuration:

```nginx
server {
    listen 443 ssl;
    server_name insights.example.com;

    ssl_certificate /etc/ssl/certs/insights.pem;
    ssl_certificate_key /etc/ssl/private/insights.key;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Monitoring

Use `make status` to check service health. For production monitoring, the key health endpoints are:

| Endpoint | What it checks |
|----------|----------------|
| `GET /health` | App is running |
| `GET /status` | App + database connectivity |
| `GET :8089/health` | RAG API is running |
| `GET :3100/health` | LLM sidecar is running |
| `GET :6333/healthz` | Qdrant is running |

## Backup

Regularly back up:

1. **MySQL data** — Both `intelis_insights` and the InteLIS database
2. **Qdrant data** — The vector index (or re-seed with `make rag-refresh` after restoring MySQL)
3. **`.env` file** — Contains all secrets and configuration

```bash
# MySQL backup
docker compose exec mysql mysqldump -u root -p"$DB_PASSWORD" --all-databases > backup.sql

# Or backup the Docker volume directly
docker run --rm -v intelis-insights_mysql_data:/data -v $(pwd):/backup alpine \
  tar czf /backup/mysql-data.tar.gz -C /data .
```
