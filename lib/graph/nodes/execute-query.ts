/**
 * Node: execute-query.
 *
 * Runs the validated SQL against the read-only InteLIS pool. Appends a
 * hard `LIMIT 10000` when the model didn't specify one — the result set
 * never exceeds the bound regardless of what the LLM produced.
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import { runLabQuery } from "@/lib/db/lab";
import { SCOPE_LIMITS } from "@/lib/config/business-rules";

const LIMIT_RE = /\blimit\s+\d+/i;

export async function executeQuery(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  if (!state.sql) {
    return {
      error: {
        code: "missing_sql",
        message: "execute-query invoked without sql",
        stage: "execute-query",
      },
    };
  }

  const sql = enforceLimit(state.sql);

  try {
    const result = await runLabQuery(sql);
    return { results: result };
  } catch (err) {
    return {
      error: {
        code: "db_error",
        message: (err as Error).message,
        stage: "execute-query",
      },
    };
  }
}

function enforceLimit(sql: string): string {
  if (LIMIT_RE.test(sql)) return sql;
  const trimmed = sql.replace(/;\s*$/, "");
  return `${trimmed} LIMIT ${SCOPE_LIMITS.maxResultLimit}`;
}
