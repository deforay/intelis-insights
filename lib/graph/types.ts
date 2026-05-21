/**
 * Shared types for the LangGraph workflow.
 *
 * Kept separate from `state.ts` so node implementations can import these
 * without depending on the Annotation runtime.
 */
import type { UserContext } from "@/lib/auth/rbac";
import type { LabQueryResult } from "@/lib/db/lab";

export type IntentKind = "count" | "list" | "aggregate" | "general";
export type IntentType = "single" | "multi_part";

export interface IntentInfo {
  type: IntentType;
  intents: IntentKind[];
  testTypes: string[];
  tables: string[];
  referencesPrevious: boolean;
}

export interface RagSnippetRef {
  id: string;
  type: string;
  text: string;
}

export interface RagContext {
  /** Pre-formatted JSON string of context items, threaded into the LLM prompt. */
  ragJson: string;
  /** Pre-formatted block listing the columns of each selected table. */
  schemaBlock: string;
  /** Snippet ids the retrieval pipeline included. */
  citations: string[];
  /** Raw snippet payloads — kept for the audit log. */
  snippets: RagSnippetRef[];
}

export interface ClarificationRequest {
  question: string;
  reason: string;
}

export interface SqlMeta {
  confidence: number;
  assumptions: string[];
  citations: string[];
  clarificationNeeded: ClarificationRequest | null;
  tokenUsage: {
    inputTokens: number | null;
    outputTokens: number | null;
    totalTokens: number | null;
  };
  modelId: string;
}

export interface AccessDecision {
  allowed: boolean;
  rewrittenSql: string | null;
  reason: string;
}

export interface ChartConfig {
  xAxis: string;
  yAxis: string;
  series: string | null;
  title: string;
}

export type ChartType =
  | "table"
  | "line"
  | "area"
  | "bar"
  | "horizontal_bar"
  | "stacked_bar"
  | "pie"
  | "donut"
  | "scatter";

export interface ChartSuggestion {
  recommended: ChartType;
  alternatives: ChartType[];
  config: ChartConfig;
  reasoning: string;
}

export type GraphStage =
  | "parse-question"
  | "retrieve-context"
  | "generate-sql"
  | "validate-access"
  | "validate-query"
  | "execute-query"
  | "format-response";

export interface GraphError {
  code: string;
  message: string;
  stage: GraphStage;
}

export type { UserContext, LabQueryResult };
