# rag-api/app/main.py
import os, hashlib, time
from typing import List, Dict, Any, Optional
from fastapi import FastAPI
from pydantic import BaseModel
from fastembed import TextEmbedding
from qdrant_client import QdrantClient

from qdrant_client.models import (
    Distance, VectorParams, PointStruct, Filter,
    FieldCondition, MatchAny, MatchText
)

QDRANT_URL = os.getenv("QDRANT_URL", "http://qdrant:6333")
COLLECTION = os.getenv("QDRANT_COLLECTION", "intelis_rag")
MODEL_NAME = os.getenv("EMBEDDING_MODEL", "BAAI/bge-small-en-v1.5")

app = FastAPI(title="RAG API", version="1.0")

# Embeddings (CPU, lightweight via fastembed)
EMB = TextEmbedding(model_name=MODEL_NAME)
# Probe dimension
_dim = len(next(EMB.embed(["dim-probe"])))
# Qdrant client + collection
QDR = QdrantClient(url=QDRANT_URL)

existing = [c.name for c in QDR.get_collections().collections]
if COLLECTION not in existing:
    QDR.recreate_collection(
        collection_name=COLLECTION,
        vectors_config=VectorParams(size=_dim, distance=Distance.COSINE),
    )

def sid_to_int(sid: str) -> int:
    # Deterministic 63-bit int id from string
    h = hashlib.sha1(sid.encode("utf-8")).hexdigest()
    return int(h[:15], 16) & ((1 << 63) - 1)

class Snippet(BaseModel):
    id: str            # your stable string id (e.g., "col:form_vl.result#abc123")
    type: str          # table|column|relationship|rule|metric|syn|exemplar|threshold|validation|test_type
    text: str
    meta: Dict[str, Any] = {}
    tags: List[str] = []

class UpsertReq(BaseModel):
    items: List[Snippet]

class SearchReq(BaseModel):
    query: str
    k: int = 8
    filters: Dict[str, Any] = {}  # e.g., {"type":["column","rule"], "table":["form_vl"]}
    # Hybrid flag reserved for future FAISS path; unused for pure Qdrant
    hybrid: bool = True

@app.post("/v1/upsert")
def upsert(req: UpsertReq):
    started = time.time()
    texts = [it.text for it in req.items]
    # fastembed returns a generator of vectors
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
            }
        ))
    QDR.upsert(collection_name=COLLECTION, points=points, wait=True)
    return {"ok": True, "count": len(points), "took_ms": int((time.time()-started)*1000)}

@app.post("/v1/search")
def search(req: SearchReq):
    started = time.time()
    qv = list(next(EMB.embed([req.query])))

    # Build payload filter
    must = []
    if "type" in req.filters and req.filters["type"]:
        types = req.filters["type"] if isinstance(req.filters["type"], list) else [req.filters["type"]]
        must.append(FieldCondition(key="type", match=MatchAny(any=types)))
    if "table" in req.filters and req.filters["table"]:
        tables = req.filters["table"] if isinstance(req.filters["table"], list) else [req.filters["table"]]
        # expects meta.table in your payload
        must.append(FieldCondition(key="meta.table", match=MatchAny(any=tables)))
    if "tag" in req.filters and req.filters["tag"]:
        tags = req.filters["tag"] if isinstance(req.filters["tag"], list) else [req.filters["tag"]]
        must.append(FieldCondition(key="tags", match=MatchAny(any=tags)))

    flt = Filter(must=must) if must else None

    res = QDR.search(
        collection_name=COLLECTION,
        query_vector=qv,
        limit=max(1, req.k or 8),
        with_payload=True,
        query_filter=flt
    )

    contexts = []
    for r in res:
        p = r.payload or {}
        m = p.get("meta") or {}
        w = float(m.get("priority", 1.0))  # <-- optional weighting
        contexts.append({
            "id": p.get("sid") or str(r.id),
            "type": p.get("type"),
            "text": p.get("text"),
            "meta": m,
            "score": float(r.score) * w,
        })

    # sort by (weighted) score desc BEFORE returning
    contexts.sort(key=lambda x: x["score"], reverse=True)

    took_ms = int((time.time() - started) * 1000)
    return {"contexts": contexts, "debug": {"backend": "qdrant", "took_ms": took_ms}}




@app.post("/v1/reset")
def reset():
    # Drop & recreate the collection with the same vector size and metric
    QDR.recreate_collection(
        collection_name=COLLECTION,
        vectors_config=VectorParams(size=_dim, distance=Distance.COSINE),
    )
    return {"ok": True}

class DeleteReq(BaseModel):
    ids: list[str]

@app.post("/v1/delete")
def delete(req: DeleteReq):
    if not req.ids:
        return {"ok": True, "count": 0}
    QDR.delete(
        collection_name=COLLECTION,
        points_selector=[sid_to_int(x) for x in req.ids],
        wait=True
    )
    return {"ok": True, "count": len(req.ids)}
