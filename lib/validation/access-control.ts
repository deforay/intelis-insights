/**
 * Row-level access control via SQL AST rewriting.
 *
 * Given a user's access scope (province / district / national) and an
 * LLM-generated SQL string, this either:
 *   - returns the SQL unchanged (national users)
 *   - injects a WHERE clause constraining facility_details to the user's
 *     allowed geographic scope (province or district users)
 *   - rejects the query when injection cannot be done safely
 *
 * Rejection causes are surfaced verbatim to the user via the audit log,
 * so the messages aim to be actionable, not vague.
 */
import { Parser } from "node-sql-parser";
import type { UserContext } from "@/lib/auth/rbac";
import type { AccessDecision } from "@/lib/graph/types";

const parser = new Parser();

const FACILITY_TABLE = "facility_details";
const PROVINCE_COLUMN = "facility_state_id";
const DISTRICT_COLUMN = "facility_district_id";

interface ScopeConstraint {
  column: string;
  values: string[];
  label: string;
}

export function enforceAccess(
  sql: string,
  ctx: UserContext,
): AccessDecision {
  if (ctx.accessLevel === "national") {
    return { allowed: true, rewrittenSql: sql, reason: "national access" };
  }

  const constraint = resolveConstraint(ctx);
  if (!constraint) {
    return {
      allowed: false,
      rewrittenSql: null,
      reason: `access level "${ctx.accessLevel}" has an empty allowed-scope list — assign at least one ${constraint === null ? "scope" : ""} to this user`,
    };
  }

  let astRaw;
  try {
    astRaw = parser.astify(sql, { database: "MySQL" });
  } catch (err) {
    return {
      allowed: false,
      rewrittenSql: null,
      reason: `SQL could not be parsed for access enforcement: ${(err as Error).message}`,
    };
  }

  if (Array.isArray(astRaw)) {
    return reject("multiple SQL statements are not supported under row-level scope enforcement");
  }
  const ast = astRaw as unknown as Record<string, unknown>;

  if (ast.type !== "select") {
    return reject(`only SELECT statements are allowed (got ${String(ast.type)})`);
  }
  if (ast.with) {
    return reject("CTEs (WITH clauses) are not yet supported for scoped users");
  }
  if (ast._next) {
    return reject("UNION queries are not yet supported for scoped users");
  }
  const fromList = ast.from;
  if (!Array.isArray(fromList) || fromList.length === 0) {
    return reject("missing FROM clause");
  }

  if (hasSubquery(fromList)) {
    return reject("subqueries in FROM are not yet supported for scoped users");
  }

  const facilityAlias = findFacilityAlias(fromList);
  if (!facilityAlias) {
    return reject(
      `query does not reference ${FACILITY_TABLE}, so it cannot be scoped to your ${constraint.label} — rephrase the question to include facility or testing-lab dimension`,
    );
  }

  const newConstraint = buildInExpr(facilityAlias, constraint);
  ast.where = ast.where
    ? {
        type: "binary_expr",
        operator: "AND",
        left: ast.where,
        right: newConstraint,
      }
    : newConstraint;

  try {
    const rewritten = parser.sqlify(ast as never, { database: "MySQL" });
    return {
      allowed: true,
      rewrittenSql: rewritten,
      reason: `injected ${constraint.label} scope (${constraint.values.length} value(s))`,
    };
  } catch (err) {
    return reject(
      `AST rewrite produced invalid SQL: ${(err as Error).message}`,
    );
  }
}

function resolveConstraint(ctx: UserContext): ScopeConstraint | null {
  if (
    ctx.accessLevel === "province" ||
    ctx.accessLevel === "multi_province"
  ) {
    if (ctx.allowedProvinces.length === 0) return null;
    return {
      column: PROVINCE_COLUMN,
      values: ctx.allowedProvinces,
      label: "province",
    };
  }
  if (ctx.accessLevel === "district" || ctx.accessLevel === "multi_district") {
    if (ctx.allowedDistricts.length === 0) return null;
    return {
      column: DISTRICT_COLUMN,
      values: ctx.allowedDistricts,
      label: "district",
    };
  }
  return null;
}

interface FromEntry {
  table?: string | null;
  as?: string | null;
  expr?: { type?: string };
}

function findFacilityAlias(fromList: FromEntry[]): string | null {
  for (const entry of fromList) {
    if (entry.table && entry.table.toLowerCase() === FACILITY_TABLE) {
      return entry.as ?? entry.table;
    }
  }
  return null;
}

function hasSubquery(fromList: FromEntry[]): boolean {
  return fromList.some((f) => f.expr?.type === "select");
}

function buildInExpr(
  facilityAlias: string,
  constraint: ScopeConstraint,
): unknown {
  return {
    type: "binary_expr",
    operator: "IN",
    left: {
      type: "column_ref",
      table: facilityAlias,
      column: constraint.column,
    },
    right: {
      type: "expr_list",
      value: constraint.values.map((v) => ({
        type: "single_quote_string",
        value: v,
      })),
    },
  };
}

function reject(reason: string): AccessDecision {
  return { allowed: false, rewrittenSql: null, reason };
}
