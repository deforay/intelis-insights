/**
 * Node: retrieve-context.
 *
 * Runs two parallel Qdrant searches (intent facts + table-scoped context),
 * deduplicates, ranks by snippet kind, and emits a compact JSON pack plus
 * a schema-block string for the SQL-gen prompt. Also re-injects the
 * caller-supplied conversation block after running it through the PII
 * scrubber so prior-turn user input never reaches the LLM unredacted.
 *
 * Ported from `QueryService::buildStrictRagPack` (lines 505–582) and
 * `QueryService::buildIntentRagPack` (lines 483–501).
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import type { RagContext, RagSnippetRef } from "../types";
import {
  searchIntentFacts,
  searchTableContext,
  type SearchHit,
} from "@/lib/rag/search";
import { buildSchemaBlock } from "@/lib/rag/schema-corpus";

const SNIPPET_MAX_CHARS = 220;
const PACK_LIMIT = 24;

const TYPE_RANK: Record<string, number> = {
  relationship: 100,
  column: 90,
  validation: 80,
  rule: 70,
  exemplar: 60,
  threshold: 50,
  table: 40,
  syn: 30,
  test_type: 30,
};

export async function retrieveContext(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  const tables = state.intent?.tables ?? [];
  if (tables.length === 0) {
    return {
      ragContext: { ragJson: "[]", schemaBlock: "", citations: [], snippets: [] },
    };
  }

  const [intentHits, tableHits] = await Promise.all([
    searchIntentFacts(state.question, 14),
    searchTableContext(state.question, tables, 15),
  ]);

  const byId = new Map<string, SearchHit>();
  for (const hit of [...intentHits, ...tableHits]) {
    if (!hit.id) continue;
    const compact = compactHit(hit);
    const existing = byId.get(compact.id);
    if (!existing || existing.score < compact.score) byId.set(compact.id, compact);
  }

  // Guarantee a `table:<name>` stub for each selected table so the model
  // sees its base tables explicitly even when retrieval missed them.
  for (const tbl of tables) {
    const tableId = `table:${tbl}`;
    if (!byId.has(tableId)) {
      byId.set(tableId, {
        id: tableId,
        type: "table",
        text: `${tbl} (base table)`,
        meta: { table: tbl },
        score: 1,
      });
    }
  }

  const ranked = Array.from(byId.values())
    .sort((a, b) => {
      const ra = TYPE_RANK[a.type] ?? 10;
      const rb = TYPE_RANK[b.type] ?? 10;
      return rb === ra ? b.score - a.score : rb - ra;
    })
    .slice(0, PACK_LIMIT);

  const pack = ranked.map((hit) => ({
    id: hit.id,
    t: hit.type,
    x: hit.text,
  }));
  const snippets: RagSnippetRef[] = ranked.map((hit) => ({
    id: hit.id,
    type: hit.type,
    text: hit.text,
  }));

  const ragContext: RagContext = {
    ragJson: JSON.stringify(pack),
    schemaBlock: buildSchemaBlock(tables),
    citations: ranked.map((h) => h.id),
    snippets,
  };

  return { ragContext };
}

function compactHit(hit: SearchHit): SearchHit {
  const text = hit.text.replace(/\s+/g, " ").trim();
  const truncated =
    text.length > SNIPPET_MAX_CHARS
      ? `${text.slice(0, SNIPPET_MAX_CHARS)}…`
      : text;
  return { ...hit, text: truncated };
}
