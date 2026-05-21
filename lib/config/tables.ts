/**
 * Canonical allowlist of base tables the SQL generator may reference.
 *
 * Mirrors the table set the corpus is built from. Adding a table here
 * without re-running `npm run rag:build && npm run rag:upsert` will let
 * the validator accept names that retrieval can't ground.
 */

export const ALLOWED_TABLES = [
  "form_vl",
  "form_eid",
  "form_covid19",
  "form_tb",
  "form_cd4",
  "form_hepatitis",
  "form_generic",
  "facility_details",
  "geographical_divisions",
  "batch_details",
  "user_details",
  "r_test_types",
  "r_results",
  "r_sample_type",
  "r_sample_rejection_reasons",
] as const;

export type AllowedTable = (typeof ALLOWED_TABLES)[number];

const ALLOWED_TABLE_SET: ReadonlySet<string> = new Set(ALLOWED_TABLES);

export function isAllowedTable(name: string): boolean {
  return ALLOWED_TABLE_SET.has(name.toLowerCase());
}
