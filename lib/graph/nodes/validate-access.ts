/**
 * Node: validate-access.
 *
 * Delegates to `enforceAccess` which either passes the SQL through
 * (national users), rewrites it with a scope-restricting WHERE clause
 * (province/district users), or rejects it.
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import { enforceAccess } from "@/lib/validation/access-control";

export async function validateAccess(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  if (!state.sql) {
    return {
      error: {
        code: "missing_sql",
        message: "validate-access invoked without sql",
        stage: "validate-access",
      },
    };
  }

  const decision = enforceAccess(state.sql, state.userContext);

  if (!decision.allowed) {
    return {
      accessDecision: decision,
      error: {
        code: "access_denied",
        message: decision.reason,
        stage: "validate-access",
      },
    };
  }

  return {
    accessDecision: decision,
    sql: decision.rewrittenSql ?? state.sql,
  };
}
