/**
 * LangGraph state schema.
 *
 * Single object threaded through every node in the workflow. Each
 * annotation uses last-write-wins (the default reducer); nodes return
 * partial state updates that get merged on top.
 */
import { Annotation } from "@langchain/langgraph";
import type {
  AccessDecision,
  ChartSuggestion,
  GraphError,
  IntentInfo,
  LabQueryResult,
  RagContext,
  SqlMeta,
  UserContext,
} from "./types";

export const GraphState = Annotation.Root({
  // ── Inputs ──────────────────────────────────────────────────────────
  question: Annotation<string>,
  sessionId: Annotation<string>,
  userContext: Annotation<UserContext>,
  /**
   * Conversation history rendered as a prompt-friendly block. Set by the
   * API route from the LangGraph checkpointer before invoking the graph.
   * Already PII-scrubbed before it enters state.
   */
  conversationBlock: Annotation<string | null>,

  // ── Node outputs ────────────────────────────────────────────────────
  intent: Annotation<IntentInfo | null>,
  ragContext: Annotation<RagContext | null>,
  sql: Annotation<string | null>,
  sqlMeta: Annotation<SqlMeta | null>,
  accessDecision: Annotation<AccessDecision | null>,
  results: Annotation<LabQueryResult | null>,
  chart: Annotation<ChartSuggestion | null>,

  // ── Control / error path ────────────────────────────────────────────
  error: Annotation<GraphError | null>,
  /** Generate-sql retries attempted so far (cap at 1 per the plan). */
  sqlRetries: Annotation<number>,

  // ── Observability ───────────────────────────────────────────────────
  traceId: Annotation<string>,
  startedAt: Annotation<number>,
});

export type GraphStateType = typeof GraphState.State;
export type GraphStateUpdate = typeof GraphState.Update;
