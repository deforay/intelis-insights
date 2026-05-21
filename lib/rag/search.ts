/**
 * Qdrant search helpers for the retrieve-context node.
 *
 * Two query shapes:
 *   - searchIntentFacts: pulls cross-cutting domain hints (synonyms,
 *     test-type rules, validations, exemplars, thresholds) regardless of
 *     table. Used to remind the model of vocabulary and constraints.
 *   - searchTableContext: pulls table-specific facts (columns,
 *     relationships, rules, validations) for the candidate tables only.
 *
 * Both run in parallel from the node. We embed the question once and
 * reuse the vector across both filters.
 */
import { qdrant } from "./qdrant";
import { embedOne } from "./embeddings";
import { env } from "@/lib/config/env";

export interface SearchHit {
  id: string;
  type: string;
  text: string;
  meta: Record<string, unknown>;
  score: number;
}

interface QdrantPayload {
  sid?: string;
  type?: string;
  text?: string;
  meta?: Record<string, unknown>;
  tags?: string[];
}

const INTENT_FACT_TYPES = [
  "syn",
  "test_type",
  "rule",
  "validation",
  "exemplar",
  "threshold",
];

const TABLE_CONTEXT_TYPES = [
  "table",
  "column",
  "relationship",
  "rule",
  "validation",
  "exemplar",
  "threshold",
];

export async function searchIntentFacts(
  question: string,
  k = 14,
): Promise<SearchHit[]> {
  const vector = await embedOne(question);
  const res = await qdrant.search(env.QDRANT_COLLECTION, {
    vector,
    limit: k,
    filter: {
      must: [
        {
          key: "type",
          match: { any: INTENT_FACT_TYPES },
        },
      ],
    },
    with_payload: true,
  });
  return res.map(toHit);
}

export async function searchTableContext(
  question: string,
  tables: readonly string[],
  k = 15,
): Promise<SearchHit[]> {
  const vector = await embedOne(question);
  const res = await qdrant.search(env.QDRANT_COLLECTION, {
    vector,
    limit: k,
    filter: {
      must: [
        {
          key: "type",
          match: { any: TABLE_CONTEXT_TYPES },
        },
      ],
      should: tables.map((t) => ({
        key: "meta.table",
        match: { value: t },
      })),
    },
    with_payload: true,
  });
  return res.map(toHit);
}

function toHit(point: {
  id: string | number;
  score: number;
  payload?: Record<string, unknown> | null;
}): SearchHit {
  const payload = (point.payload ?? {}) as QdrantPayload;
  return {
    id: payload.sid ?? String(point.id),
    type: payload.type ?? "",
    text: payload.text ?? "",
    meta: payload.meta ?? {},
    score: point.score,
  };
}
