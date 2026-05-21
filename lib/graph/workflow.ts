/**
 * LangGraph workflow assembly.
 *
 * Wires the seven nodes into a `StateGraph`, attaches the Postgres
 * checkpointer, and exposes a `getGraph()` singleton. The graph is
 * compiled lazily on first call so module import doesn't reach for the
 * database.
 *
 * Flow:
 *   START
 *     → parse-question
 *     → retrieve-context
 *     → generate-sql
 *       ├── error/no-sql ────────────────────────────────► format-response
 *       └── ok → validate-access
 *                 ├── denied ─────────────────────────────► format-response
 *                 └── allowed → validate-query
 *                                ├── ok → execute-query ──► format-response
 *                                ├── error + retries < 1 ─► generate-sql
 *                                └── error + retries ≥ 1 ─► format-response
 *     → END
 */
import { END, START, StateGraph } from "@langchain/langgraph";
import type { PostgresSaver } from "@langchain/langgraph-checkpoint-postgres";
import { GraphState } from "./state";
import { getCheckpointer } from "./checkpointer";
import { parseQuestion } from "./nodes/parse-question";
import { retrieveContext } from "./nodes/retrieve-context";
import { generateSql } from "./nodes/generate-sql";
import { validateAccess } from "./nodes/validate-access";
import { validateQuery } from "./nodes/validate-query";
import { executeQuery } from "./nodes/execute-query";
import { narrateResult } from "./nodes/narrate-result";
import { formatResponse } from "./nodes/format-response";
import {
  afterGenerateSql,
  afterValidateAccess,
  afterValidateQuery,
} from "./routing";

type CompiledGraph = ReturnType<typeof buildWorkflow>;

let cached: Promise<CompiledGraph> | null = null;

export function getGraph(): Promise<CompiledGraph> {
  if (!cached) cached = compile();
  return cached;
}

async function compile(): Promise<CompiledGraph> {
  const checkpointer = await getCheckpointer();
  return buildWorkflow(checkpointer);
}

export function buildWorkflow(checkpointer: PostgresSaver) {
  return new StateGraph(GraphState)
    .addNode("parse-question", parseQuestion)
    .addNode("retrieve-context", retrieveContext)
    .addNode("generate-sql", generateSql)
    .addNode("validate-access", validateAccess)
    .addNode("validate-query", validateQuery)
    .addNode("execute-query", executeQuery)
    .addNode("narrate-result", narrateResult)
    .addNode("format-response", formatResponse)
    .addEdge(START, "parse-question")
    .addEdge("parse-question", "retrieve-context")
    .addEdge("retrieve-context", "generate-sql")
    .addConditionalEdges("generate-sql", afterGenerateSql, [
      "validate-access",
      "format-response",
    ])
    .addConditionalEdges("validate-access", afterValidateAccess, [
      "validate-query",
      "format-response",
    ])
    .addConditionalEdges("validate-query", afterValidateQuery, [
      "execute-query",
      "generate-sql",
      "format-response",
    ])
    .addEdge("execute-query", "narrate-result")
    .addEdge("narrate-result", "format-response")
    .addEdge("format-response", END)
    .compile({ checkpointer });
}
