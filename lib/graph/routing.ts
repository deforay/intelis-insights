/**
 * Conditional-edge routers for the LangGraph workflow.
 *
 * Kept separate from `workflow.ts` so they can be unit-tested in
 * isolation without spinning up the StateGraph runtime.
 */
import type { GraphStateType } from "./state";

const MAX_SQL_RETRIES = 1;

export function afterGenerateSql(
  state: GraphStateType,
): "validate-access" | "format-response" {
  if (state.error || !state.sql) return "format-response";
  return "validate-access";
}

export function afterValidateAccess(
  state: GraphStateType,
): "validate-query" | "format-response" {
  if (state.error || !state.accessDecision?.allowed) return "format-response";
  return "validate-query";
}

export function afterValidateQuery(
  state: GraphStateType,
): "execute-query" | "generate-sql" | "format-response" {
  if (!state.error) return "execute-query";
  if ((state.sqlRetries ?? 0) < MAX_SQL_RETRIES) return "generate-sql";
  return "format-response";
}
