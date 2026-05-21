/**
 * Snippet types stored in Qdrant. One snippet per Qdrant point; the snippet's
 * `text` is embedded and the rest of the fields ride along as the point
 * payload for filtering and citation at retrieval time.
 *
 * Schema preserved from the retired Python rag-api so existing tooling /
 * mental models port over.
 */

export type SnippetType =
  | "column"
  | "table"
  | "relationship"
  | "rule"
  | "syn" // synonym
  | "exemplar" // SQL pattern example
  | "threshold" // clinical threshold
  | "validation"
  | "test_type";

export interface Snippet {
  id: string;
  type: SnippetType;
  text: string;
  meta: Record<string, unknown>;
  tags: string[];
}

export interface SnippetPayload {
  sid: string;
  type: SnippetType;
  text: string;
  meta: Record<string, unknown>;
  tags: string[];
}

export function snippetIdToPointId(sid: string): string {
  // Qdrant accepts UUIDs or unsigned 64-bit ints as point IDs. We're using
  // the sha1 hex of the sid, taking the first 32 chars to form a UUID-like
  // structure (8-4-4-4-12 pattern).
  const crypto = require("node:crypto");
  const hex: string = crypto.createHash("sha1").update(sid).digest("hex");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
}
