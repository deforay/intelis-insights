/**
 * Node: validate-query.
 *
 * SELECT-only, table allowlist, no forbidden PII columns. Throws are
 * mapped to a graph error that the workflow uses to decide whether to
 * retry generate-sql or terminate via format-response.
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import { SqlValidationError, validateSql } from "@/lib/validation/safety";

export async function validateQuery(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  if (!state.sql) {
    return {
      error: {
        code: "missing_sql",
        message: "validate-query invoked without sql",
        stage: "validate-query",
      },
    };
  }

  try {
    validateSql(state.sql);
    return { error: null };
  } catch (err) {
    if (err instanceof SqlValidationError) {
      return {
        error: {
          code: err.code,
          message:
            "The generated SQL did not pass safety validation. Please refine your question and try again.",
          internalMessage: err.message,
          stage: "validate-query",
        },
      };
    }
    return {
      error: {
        code: "validator_error",
        message:
          "The generated SQL could not be validated. Please refine your question and try again.",
        internalMessage: (err as Error).message,
        stage: "validate-query",
      },
    };
  }
}
