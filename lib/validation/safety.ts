/**
 * SQL safety validator — SELECT-only, allowlist tables, no PII leakage.
 *
 * Ported from `QueryService::validateSql` (lines 1028–1066) with the
 * `COUNT(DISTINCT …)` carve-out for the small set of identifier columns
 * declared in `ALLOW_AGGREGATED_DISTINCT`. The validator throws a
 * `SqlValidationError` with a short, actionable message that gets
 * threaded into the retry prompt on a second attempt.
 */
import {
  ALLOW_AGGREGATED_DISTINCT,
  FORBIDDEN_COLUMNS,
  REJECT_PATTERNS,
} from "@/lib/config/business-rules";
import { isAllowedTable } from "@/lib/config/tables";

export class SqlValidationError extends Error {
  readonly code: string;
  constructor(code: string, message: string) {
    super(message);
    this.code = code;
    this.name = "SqlValidationError";
  }
}

const SELECT_RE = /^\s*select\s/i;
const FROM_RE = /\bFROM\s+([a-zA-Z0-9_]+)/i;
const TABLE_REFS_RE = /\b(?:from|join)\s+`?([a-zA-Z0-9_]+)`?/gi;

export function validateSql(sql: string): void {
  if (!SELECT_RE.test(sql)) {
    throw new SqlValidationError(
      "not_select",
      `non-SELECT statement returned: ${truncate(sql)}`,
    );
  }
  if (!FROM_RE.test(sql)) {
    throw new SqlValidationError(
      "missing_from",
      "generated SQL is missing a FROM clause",
    );
  }

  for (const pattern of REJECT_PATTERNS) {
    if (pattern.test(sql)) {
      throw new SqlValidationError(
        "reject_pattern",
        `SQL contains a forbidden pattern: ${pattern.source}`,
      );
    }
  }

  for (const match of sql.matchAll(TABLE_REFS_RE)) {
    const table = match[1];
    if (!isAllowedTable(table)) {
      throw new SqlValidationError(
        "disallowed_table",
        `table not in allowlist: ${table}`,
      );
    }
  }

  enforcePrivacy(sql);
}

function enforcePrivacy(sql: string): void {
  const stripped = stripStringLiterals(sql);
  let scrubbed = stripped;
  for (const col of ALLOW_AGGREGATED_DISTINCT) {
    const safePattern = new RegExp(
      `count\\s*\\(\\s*distinct\\s+[^)]*\\b${escape(col)}\\b[^)]*\\)`,
      "gi",
    );
    scrubbed = scrubbed.replace(safePattern, "/*__SAFE_AGG__*/");
  }
  for (const col of FORBIDDEN_COLUMNS) {
    const re = new RegExp(`\\b${escape(col)}\\b`, "i");
    if (re.test(scrubbed)) {
      throw new SqlValidationError(
        "privacy_violation",
        `query selects forbidden patient-identifier column "${col}" outside of COUNT(DISTINCT …)`,
      );
    }
  }
}

function stripStringLiterals(sql: string): string {
  // Replace 'single-quoted' and "double-quoted" string literals with
  // empty placeholders so identifier-name scans don't false-positive on
  // a literal containing a forbidden column name.
  return sql
    .replace(/'([^'\\]|\\.)*'/g, "''")
    .replace(/"([^"\\]|\\.)*"/g, '""');
}

function escape(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function truncate(s: string, n = 120): string {
  return s.length <= n ? s : `${s.slice(0, n)}…`;
}
