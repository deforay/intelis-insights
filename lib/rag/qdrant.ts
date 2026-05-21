/**
 * Qdrant client + collection bootstrap.
 *
 * One singleton client per process. `ensureCollection()` is idempotent —
 * creates the collection with the configured embedder's dimension if it's
 * missing, returns immediately if it exists.
 */
import { QdrantClient } from "@qdrant/js-client-rest";
import { env } from "@/lib/config/env";
import {
  type Snippet,
  type SnippetPayload,
  snippetIdToPointId,
} from "./snippets";

declare global {
  // eslint-disable-next-line no-var
  var __qdrantClient: QdrantClient | undefined;
}

function buildClient(): QdrantClient {
  return new QdrantClient({
    url: env.QDRANT_URL,
    ...(env.QDRANT_API_KEY ? { apiKey: env.QDRANT_API_KEY } : {}),
  });
}

export const qdrant = globalThis.__qdrantClient ?? buildClient();

if (env.NODE_ENV !== "production") {
  globalThis.__qdrantClient = qdrant;
}

export async function ensureCollection(
  collectionName: string,
  vectorDim: number,
): Promise<void> {
  const exists = await qdrant.collectionExists(collectionName);
  if (exists.exists) return;

  await qdrant.createCollection(collectionName, {
    vectors: { size: vectorDim, distance: "Cosine" },
  });
  console.log(
    `Created Qdrant collection ${collectionName} (dim=${vectorDim}, distance=Cosine)`,
  );
}

export interface SnippetWithVector {
  snippet: Snippet;
  vector: number[];
}

export async function upsertSnippets(
  collectionName: string,
  items: SnippetWithVector[],
): Promise<void> {
  if (items.length === 0) return;
  const points = items.map(({ snippet, vector }) => {
    const payload: SnippetPayload = {
      sid: snippet.id,
      type: snippet.type,
      text: snippet.text,
      meta: snippet.meta,
      tags: snippet.tags,
    };
    return {
      id: snippetIdToPointId(snippet.id),
      vector,
      payload: payload as unknown as Record<string, unknown>,
    };
  });
  await qdrant.upsert(collectionName, { points, wait: true });
}

export async function resetCollection(collectionName: string): Promise<void> {
  const exists = await qdrant.collectionExists(collectionName);
  if (exists.exists) {
    await qdrant.deleteCollection(collectionName);
  }
}
