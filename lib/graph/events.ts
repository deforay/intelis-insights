/**
 * Event types streamed by the graph runner to the API client.
 *
 * NDJSON over the wire — one `QueryEvent` per line. The UI consumes
 * these to drive its per-stage skeleton states, citation pills,
 * assumption banner, etc.
 */
import type {
  AccessDecision,
  ChartSuggestion,
  ClarificationRequest,
  GraphStage,
  IntentInfo,
  LabQueryResult,
} from "./types";

export type QueryEvent =
  | { type: "session"; sessionId: string; traceId: string }
  | { type: "stage"; stage: GraphStage }
  | { type: "intent"; intent: IntentInfo }
  | {
      type: "rag";
      citations: string[];
      snippetCount: number;
    }
  | {
      type: "sql";
      sql: string;
      confidence: number;
      assumptions: string[];
      citations: string[];
      clarificationNeeded: ClarificationRequest | null;
    }
  | {
      type: "access";
      decision: AccessDecision;
    }
  | { type: "results"; results: LabQueryResult }
  | { type: "chart"; chart: ChartSuggestion }
  | {
      type: "error";
      code: string;
      message: string;
      stage: GraphStage;
    }
  | { type: "done"; auditId: string | null; durationMs: number };
