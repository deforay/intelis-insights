# rag-api/app/main.py
import os, hashlib, time, logging
from typing import List, Dict, Any, Optional
from contextlib import asynccontextmanager
from fastapi import FastAPI
from pydantic import BaseModel

log = logging.getLogger("rag-api")
logging.basicConfig(level=logging.INFO)

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
QDRANT_URL = os.getenv("QDRANT_URL", "http://qdrant:6333")
MODEL_NAME = os.getenv("EMBEDDING_MODEL", "BAAI/bge-small-en-v1.5")
COLLECTIONS = ["docs_index", "memory_index"]

# Globals — set during lifespan startup
EMB = None
QDR = None
_dim = None


# ---------------------------------------------------------------------------
# Lifespan — deferred init so uvicorn doesn't hang at import
# ---------------------------------------------------------------------------
def _init_blocking():
    """Run all blocking init in a plain thread to avoid event-loop conflicts."""
    global EMB, QDR, _dim
    import requests as req

    # 1. Load embedding model
    log.info("Loading embedding model %s ...", MODEL_NAME)
    from fastembed import TextEmbedding
    EMB = TextEmbedding(model_name=MODEL_NAME)
    _dim = len(next(EMB.embed(["dim-probe"])))
    log.info("Embedding dim = %d", _dim)

    # 2. Wait for Qdrant to be reachable via raw HTTP
    for attempt in range(30):
        try:
            r = req.get(f"{QDRANT_URL}/healthz", timeout=5)
            log.info("Qdrant reachable (attempt %d): %s", attempt + 1, r.text.strip())
            break
        except Exception as exc:
            if attempt == 29:
                raise RuntimeError(f"Qdrant unreachable after 30 attempts: {exc}")
            log.warning("Qdrant not ready (attempt %d): %s", attempt + 1, exc)
            time.sleep(2)

    # 3. Now create the QdrantClient
    from qdrant_client import QdrantClient
    from qdrant_client.models import Distance, VectorParams

    QDR = QdrantClient(url=QDRANT_URL, timeout=10, prefer_grpc=False)
    existing = {c.name for c in QDR.get_collections().collections}
    for col in COLLECTIONS:
        if col not in existing:
            QDR.recreate_collection(
                collection_name=col,
                vectors_config=VectorParams(size=_dim, distance=Distance.COSINE),
            )
    log.info("Qdrant collections ready: %s", list(existing | set(COLLECTIONS)))


@asynccontextmanager
async def lifespan(application: FastAPI):
    import asyncio
    await asyncio.to_thread(_init_blocking)
    yield


app = FastAPI(title="RAG API – Intelis Insights", version="2.0", lifespan=lifespan)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _resolve_collection(name: Optional[str]) -> str:
    if name and name in COLLECTIONS:
        return name
    return "docs_index"


def sid_to_int(sid: str) -> int:
    h = hashlib.sha1(sid.encode("utf-8")).hexdigest()
    return int(h[:15], 16) & ((1 << 63) - 1)


# ---------------------------------------------------------------------------
# Request / response models
# ---------------------------------------------------------------------------

class Snippet(BaseModel):
    id: str
    type: str
    text: str
    meta: Dict[str, Any] = {}
    tags: List[str] = []


class UpsertReq(BaseModel):
    items: List[Snippet]
    collection: Optional[str] = "docs_index"


class SearchReq(BaseModel):
    query: str
    k: int = 8
    filters: Dict[str, Any] = {}
    collection: Optional[str] = "docs_index"


class ResetReq(BaseModel):
    collection: Optional[str] = "docs_index"


class DeleteReq(BaseModel):
    ids: List[str]
    collection: Optional[str] = "docs_index"


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@app.get("/health")
def health():
    if QDR is None:
        return {"status": "starting", "detail": "initializing"}
    try:
        cols = [c.name for c in QDR.get_collections().collections]
        return {"status": "ok", "collections": cols, "embedding_model": MODEL_NAME}
    except Exception as e:
        return {"status": "error", "detail": str(e)}


@app.post("/v1/upsert")
def upsert(req: UpsertReq):
    from qdrant_client.models import PointStruct
    col = _resolve_collection(req.collection)
    started = time.time()

    texts = [it.text for it in req.items]
    vecs = list(EMB.embed(texts))

    points = []
    for it, v in zip(req.items, vecs):
        points.append(PointStruct(
            id=sid_to_int(it.id),
            vector=list(v),
            payload={
                "sid": it.id,
                "type": it.type,
                "text": it.text,
                "meta": it.meta,
                "tags": it.tags,
            },
        ))

    QDR.upsert(collection_name=col, points=points, wait=True)
    return {
        "ok": True,
        "collection": col,
        "count": len(points),
        "took_ms": int((time.time() - started) * 1000),
    }


@app.post("/v1/search")
def search(req: SearchReq):
    from qdrant_client.models import Filter, FieldCondition, MatchAny
    col = _resolve_collection(req.collection)
    started = time.time()

    qv = list(next(EMB.embed([req.query])))

    must = []
    if "type" in req.filters and req.filters["type"]:
        types = req.filters["type"] if isinstance(req.filters["type"], list) else [req.filters["type"]]
        must.append(FieldCondition(key="type", match=MatchAny(any=types)))
    if "metric" in req.filters and req.filters["metric"]:
        metrics = req.filters["metric"] if isinstance(req.filters["metric"], list) else [req.filters["metric"]]
        must.append(FieldCondition(key="meta.metric_id", match=MatchAny(any=metrics)))
    if "tag" in req.filters and req.filters["tag"]:
        tags = req.filters["tag"] if isinstance(req.filters["tag"], list) else [req.filters["tag"]]
        must.append(FieldCondition(key="tags", match=MatchAny(any=tags)))
    if "table" in req.filters and req.filters["table"]:
        tables = req.filters["table"] if isinstance(req.filters["table"], list) else [req.filters["table"]]
        must.append(FieldCondition(key="meta.table", match=MatchAny(any=tables)))
    # Support type_in alias (backward compat with old QueryService)
    if "type_in" in req.filters and req.filters["type_in"]:
        types = req.filters["type_in"] if isinstance(req.filters["type_in"], list) else [req.filters["type_in"]]
        must.append(FieldCondition(key="type", match=MatchAny(any=types)))

    flt = Filter(must=must) if must else None

    res = QDR.search(
        collection_name=col,
        query_vector=qv,
        limit=max(1, req.k or 8),
        with_payload=True,
        query_filter=flt,
    )

    contexts = []
    for r in res:
        p = r.payload or {}
        m = p.get("meta") or {}
        w = float(m.get("priority", 1.0))
        contexts.append({
            "id": p.get("sid") or str(r.id),
            "type": p.get("type"),
            "text": p.get("text"),
            "meta": m,
            "score": float(r.score) * w,
        })

    contexts.sort(key=lambda x: x["score"], reverse=True)

    took_ms = int((time.time() - started) * 1000)
    return {
        "contexts": contexts,
        "debug": {"backend": "qdrant", "collection": col, "took_ms": took_ms},
    }


@app.post("/v1/reset")
def reset(req: ResetReq = ResetReq()):
    from qdrant_client.models import Distance, VectorParams
    col = _resolve_collection(req.collection)
    QDR.recreate_collection(
        collection_name=col,
        vectors_config=VectorParams(size=_dim, distance=Distance.COSINE),
    )
    return {"ok": True, "collection": col}


@app.post("/v1/delete")
def delete(req: DeleteReq):
    col = _resolve_collection(req.collection)
    if not req.ids:
        return {"ok": True, "collection": col, "count": 0}
    QDR.delete(
        collection_name=col,
        points_selector=[sid_to_int(x) for x in req.ids],
        wait=True,
    )
    return {"ok": True, "collection": col, "count": len(req.ids)}
