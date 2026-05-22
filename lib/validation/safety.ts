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
  SCOPE_LIMITS,
} from "@/lib/config/business-rules";
import { isAllowedTable } from "@/lib/config/tables";
import { Parser } from "node-sql-parser";

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
const COMMENT_RE = /\/\*|--|#/;
const DANGEROUS_FUNCTIONS = new Set(["benchmark", "load_file", "sleep"]);
const parser = new Parser();

type SqlAstNode = Record<string, unknown>;

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

  if (COMMENT_RE.test(stripStringLiterals(sql))) {
    throw new SqlValidationError(
      "comments_not_allowed",
      "SQL comments are not allowed in generated queries",
    );
  }

  enforceStructuralSafety(sql);
  enforcePrivacy(sql);
}

function enforceStructuralSafety(sql: string): void {
  let astRaw: unknown;
  try {
    astRaw = parser.astify(sql, { database: "MySQL" });
  } catch (err) {
    throw new SqlValidationError(
      "parse_error",
      `generated SQL could not be parsed: ${(err as Error).message}`,
    );
  }

  if (Array.isArray(astRaw)) {
    throw new SqlValidationError(
      "multiple_statements",
      "generated SQL must contain exactly one SELECT statement",
    );
  }

  const ast = astRaw as SqlAstNode;
  if (ast.type !== "select") {
    throw new SqlValidationError(
      "not_select",
      `only SELECT statements are allowed (got ${String(ast.type)})`,
    );
  }
  if (ast.with) {
    throw new SqlValidationError(
      "cte_not_allowed",
      "CTEs (WITH clauses) are not allowed in generated SQL",
    );
  }
  if (ast._next || ast.set_op) {
    throw new SqlValidationError(
      "union_not_allowed",
      "UNION queries are not allowed in generated SQL",
    );
  }
  if (hasIntoTarget(ast.into)) {
    throw new SqlValidationError(
      "select_into_not_allowed",
      "SELECT ... INTO is not allowed in generated SQL",
    );
  }

  enforceFromList(ast.from);
  enforceSelectList(ast);
  enforceLimit(ast.limit);
  rejectDangerousFunctions(ast);
}

function enforceFromList(fromRaw: unknown): void {
  if (!Array.isArray(fromRaw) || fromRaw.length === 0) {
    throw new SqlValidationError(
      "missing_from",
      "generated SQL is missing a FROM clause",
    );
  }

  for (const entry of fromRaw) {
    if (!isRecord(entry)) {
      throw new SqlValidationError(
        "invalid_from",
        "generated SQL contains an invalid FROM entry",
      );
    }
    if (entry.db) {
      throw new SqlValidationError(
        "schema_qualified_table",
        "schema-qualified table names are not allowed",
      );
    }
    if (entry.expr) {
      throw new SqlValidationError(
        "subquery_not_allowed",
        "subqueries are not allowed in generated SQL",
      );
    }

    const table = typeof entry.table === "string" ? entry.table : null;
    if (!table) {
      throw new SqlValidationError(
        "missing_table",
        "generated SQL contains a FROM entry without a table name",
      );
    }
    if (!isAllowedTable(table)) {
      throw new SqlValidationError(
        "disallowed_table",
        `table not in allowlist: ${table}`,
      );
    }
  }
}

function enforceSelectList(ast: SqlAstNode): void {
  const columns = ast.columns;
  if (!Array.isArray(columns) || columns.length === 0) {
    throw new SqlValidationError(
      "missing_select_list",
      "generated SQL is missing a SELECT list",
    );
  }

  let hasAggregate = false;
  let hasUngroupedRawProjection = false;
  const hasGroupBy = hasGroupByColumns(ast.groupby);

  for (const column of columns) {
    if (!isRecord(column)) continue;
    const expr = column.expr;
    if (isWildcardProjection(expr)) {
      throw new SqlValidationError(
        "wildcard_select",
        "wildcard projections such as SELECT * or table.* are not allowed",
      );
    }
    if (containsAggregate(expr)) hasAggregate = true;
    if (containsColumnReference(expr) && !containsAggregate(expr)) {
      hasUngroupedRawProjection = true;
    }
  }

  if (hasAggregate && hasUngroupedRawProjection && !hasGroupBy) {
    throw new SqlValidationError(
      "ungrouped_raw_column",
      "aggregate queries cannot include raw columns unless they are grouped",
    );
  }
}

function enforceLimit(limitRaw: unknown): void {
  if (!isRecord(limitRaw)) return;
  const values = Array.isArray(limitRaw.value) ? limitRaw.value : [];
  for (const item of values) {
    if (!isRecord(item) || item.type !== "number") continue;
    const value = Number(item.value);
    if (Number.isFinite(value) && value > SCOPE_LIMITS.maxResultLimit) {
      throw new SqlValidationError(
        "limit_too_large",
        `LIMIT must not exceed ${SCOPE_LIMITS.maxResultLimit}`,
      );
    }
  }
}

function rejectDangerousFunctions(ast: SqlAstNode): void {
  walk(ast, (node, isRoot) => {
    if (!isRoot && node.type === "select") {
      throw new SqlValidationError(
        "subquery_not_allowed",
        "subqueries are not allowed in generated SQL",
      );
    }
    if (node.type !== "function") return;
    const name = functionName(node);
    if (name && DANGEROUS_FUNCTIONS.has(name)) {
      throw new SqlValidationError(
        "dangerous_function",
        `function ${name.toUpperCase()}() is not allowed in generated SQL`,
      );
    }
  });
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

function hasIntoTarget(into: unknown): boolean {
  return isRecord(into) && typeof into.keyword === "string";
}

function hasGroupByColumns(groupby: unknown): boolean {
  if (Array.isArray(groupby)) return groupby.length > 0;
  return (
    isRecord(groupby) &&
    Array.isArray(groupby.columns) &&
    groupby.columns.length > 0
  );
}

function isWildcardProjection(expr: unknown): boolean {
  if (!isRecord(expr)) return false;
  if (expr.type === "star") return true;
  return expr.type === "column_ref" && expr.column === "*";
}

function containsAggregate(value: unknown): boolean {
  let found = false;
  walk(value, (node) => {
    if (node.type === "aggr_func") found = true;
  });
  return found;
}

function containsColumnReference(value: unknown): boolean {
  let found = false;
  walk(value, (node) => {
    if (node.type === "column_ref" && node.column !== "*") found = true;
  });
  return found;
}

function functionName(node: SqlAstNode): string | null {
  const name = node.name;
  if (typeof name === "string") return name.toLowerCase();
  if (!isRecord(name) || !Array.isArray(name.name)) return null;
  const parts = name.name
    .map((part) =>
      isRecord(part) && typeof part.value === "string" ? part.value : null,
    )
    .filter((part): part is string => !!part);
  return parts.at(-1)?.toLowerCase() ?? null;
}

function walk(
  value: unknown,
  visit: (node: SqlAstNode, isRoot: boolean) => void,
  isRoot = true,
): void {
  if (Array.isArray(value)) {
    for (const item of value) walk(item, visit, false);
    return;
  }
  if (!isRecord(value)) return;
  visit(value, isRoot);
  for (const child of Object.values(value)) {
    walk(child, visit, false);
  }
}

function isRecord(value: unknown): value is SqlAstNode {
  return typeof value === "object" && value !== null;
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
