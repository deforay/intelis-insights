import type {
  AccessDecision,
  ChartSuggestion,
  ClarificationRequest,
  GraphStage,
  IntentInfo,
  LabQueryResult,
} from "@/lib/graph/types";

export interface UserTurn {
  id: string;
  role: "user";
  content: string;
  createdAt: number;
}

export interface AssistantTurn {
  id: string;
  role: "assistant";
  createdAt: number;
  intent: IntentInfo | null;
  citationCount: number;
  sql: string | null;
  sqlConfidence: number | null;
  assumptions: string[];
  citations: string[];
  clarificationNeeded: ClarificationRequest | null;
  accessDecision: AccessDecision | null;
  results: LabQueryResult | null;
  /** Natural-language summary of the result. */
  narration: string | null;
  /** Suggested follow-up questions the user might ask next. */
  followUps: string[];
  chart: ChartSuggestion | null;
  error: { code: string; message: string; stage: GraphStage } | null;
  traceId: string | null;
  durationMs: number | null;
  /** Per-stage progress: undefined → pending, true → done, false → skipped */
  stages: Partial<Record<GraphStage, boolean>>;
  isStreaming: boolean;
}

export type ChatTurn = UserTurn | AssistantTurn;

export function createUserTurn(content: string): UserTurn {
  return {
    id: cryptoId(),
    role: "user",
    content,
    createdAt: Date.now(),
  };
}

export function createAssistantTurn(): AssistantTurn {
  return {
    id: cryptoId(),
    role: "assistant",
    createdAt: Date.now(),
    intent: null,
    citationCount: 0,
    sql: null,
    sqlConfidence: null,
    assumptions: [],
    citations: [],
    clarificationNeeded: null,
    accessDecision: null,
    results: null,
    narration: null,
    followUps: [],
    chart: null,
    error: null,
    traceId: null,
    durationMs: null,
    stages: {},
    isStreaming: true,
  };
}

function cryptoId(): string {
  if (typeof crypto !== "undefined" && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
