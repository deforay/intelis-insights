# First-Run Checklist

Use this checklist to verify that everything is working after setup.

## Service Health

Run `make status` (Docker) or check each service manually:

| Check | Command | Expected |
|-------|---------|----------|
| App is running | `curl http://localhost:8080/health` | `{"status":"ok"}` |
| Database connected | `curl http://localhost:8080/status` | `"database":"connected"` |
| RAG API is running | `curl http://localhost:8089/health` | `{"status":"ok"}` |
| LLM Sidecar is running | `curl http://localhost:3100/health` | `{"status":"ok"}` |
| Qdrant is running | `curl http://localhost:6333/healthz` | HTTP 200 |

## Application

- [ ] Open [http://localhost:8080](http://localhost:8080) in your browser
- [ ] Dashboard page loads (charts may be empty without InteLIS data)
- [ ] Chat tab is visible and accessible

## Chat (Requires InteLIS Data + API Key)

If you have both InteLIS data imported and an LLM API key configured:

```bash
curl -X POST http://localhost:8080/api/v1/chat/ask \
  -H 'Content-Type: application/json' \
  -d '{"question": "How many viral load tests were done last month?"}'
```

Expected: a JSON response with `sql`, `data`, and `chart` fields.

## RAG Index (Requires InteLIS Data)

If you've run `make rag-refresh`:

```bash
curl -X POST http://localhost:8089/v1/search \
  -H 'Content-Type: application/json' \
  -d '{"query": "viral load suppression", "k": 3}'
```

Expected: a JSON response with matching snippets about VL suppression thresholds.

## What If Something Fails?

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| App shows "not ready" | Container still starting | Wait 30 seconds, try again |
| Database shows "not connected" | MySQL not ready or wrong credentials | Check `DB_PASSWORD` in `.env` |
| RAG API shows "not ready" | Downloading embedding model (first run) | Wait up to 60 seconds |
| LLM Sidecar shows "not ready" | Missing `llm-sidecar/` files | Run `git submodule update --init --recursive` |
| Chat returns an error | No API key or no InteLIS data | Check `.env` for API keys; see [Connecting InteLIS Data](../guides/connecting-intelis-data.md) |

For more detailed solutions, see [Troubleshooting](../troubleshooting.md).
