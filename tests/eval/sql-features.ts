/**
 * Regex-based feature extraction for SQL strings.
 *
 * Used by the eval harness to assert generated SQL has the right
 * shape — table references, JOIN type, GROUP BY, column aliases,
 * absence of forbidden columns, etc. Not a SQL parser; the heuristics
 * are good enough for the test patterns we care about.
 */

export interface SqlFeatures {
  isSelectOnly: boolean;
  tables: string[];
  hasJoin: boolean;
  hasInnerJoin: boolean;
  hasLeftJoin: boolean;
  hasGroupBy: boolean;
  hasLimit: boolean;
  hasAlias: boolean;
  /** lowercased SQL with string literals stripped */
  scrubbed: string;
}

export function extractFeatures(sql: string): SqlFeatures {
  const stripped = stripStringLiterals(sql);
  const lower = stripped.toLowerCase();

  const tableRegex = /\b(?:from|join)\s+`?([a-z0-9_]+)`?/gi;
  const tables = new Set<string>();
  for (const m of stripped.matchAll(tableRegex)) {
    tables.add(m[1].toLowerCase());
  }

  return {
    isSelectOnly: /^\s*select\s/i.test(sql),
    tables: Array.from(tables),
    hasJoin: /\bjoin\b/i.test(lower),
    hasInnerJoin: /\binner\s+join\b/i.test(lower),
    hasLeftJoin: /\bleft(\s+outer)?\s+join\b/i.test(lower),
    hasGroupBy: /\bgroup\s+by\b/i.test(lower),
    hasLimit: /\blimit\s+\d+/i.test(lower),
    // "AS something" or "AS 'something'" or "AS \"something\""
    hasAlias: /\bas\s+(?:`[^`]+`|"[^"]+"|'[^']+'|[a-z_][a-z0-9_]*)/i.test(stripped),
    scrubbed: lower,
  };
}

function stripStringLiterals(sql: string): string {
  return sql
    .replace(/'([^'\\]|\\.)*'/g, "''")
    .replace(/"([^"\\]|\\.)*"/g, '""');
}
