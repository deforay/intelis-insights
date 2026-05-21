/**
 * Streaming runner for the LangGraph workflow.
 *
 * Wraps `graph.stream()` and converts LangGraph's `{nodeName: update}`
 * payloads into typed `QueryEvent`s the API surface can serialise to
 * NDJSON. Also accumulates the final state so the route handler can
 * write a single audit row after the stream completes.
 */
import { randomUUID } from "node:crypto";
import { getGraph } from "./workflow";
import type { QueryEvent } from "./events";
import type { GraphStateType } from "./state";
import type { GraphStage, UserContext } from "./types";

export interface RunQueryInput {
  question: string;
  sessionId: string;
  userContext: UserContext;
  conversationBlock: string | null;
}

export interface RunQueryResult {
  events: AsyncGenerator<QueryEvent>;
  /** Resolves to the accumulated final state once `events` has fully iterated. */
  final: Promise<GraphStateType>;
}

const STAGE_KEYS: Record<string, GraphStage> = {
  "parse-question": "parse-question",
  "retrieve-context": "retrieve-context",
  "generate-sql": "generate-sql",
  "validate-access": "validate-access",
  "validate-query": "validate-query",
  "execute-query": "execute-query",
  "format-response": "format-response",
};

export async function runQuery(input: RunQueryInput): Promise<RunQueryResult> {
  const graph = await getGraph();
  const traceId = randomUUID();

  const initial: Partial<GraphStateType> = {
    question: input.question,
    sessionId: input.sessionId,
    userContext: input.userContext,
    conversationBlock: input.conversationBlock,
    intent: null,
    ragContext: null,
    sql: null,
    sqlMeta: null,
    accessDecision: null,
    results: null,
    chart: null,
    error: null,
    sqlRetries: 0,
    traceId,
    startedAt: Date.now(),
  };

  let resolveFinal!: (s: GraphStateType) => void;
  let rejectFinal!: (e: unknown) => void;
  const final = new Promise<GraphStateType>((res, rej) => {
    resolveFinal = res;
    rejectFinal = rej;
  });

  async function* events(): AsyncGenerator<QueryEvent> {
    yield { type: "session", sessionId: input.sessionId, traceId };
    const accumulated = { ...initial } as GraphStateType;
    try {
      const stream = await graph.stream(initial, {
        configurable: { thread_id: input.sessionId },
        streamMode: "updates",
      });
      for await (const update of stream) {
        for (const [nodeKey, partial] of Object.entries(
          update as Record<string, Partial<GraphStateType>>,
        )) {
          const stage = STAGE_KEYS[nodeKey];
          if (stage) yield { type: "stage", stage };
          Object.assign(accumulated, partial);
          yield* expandNodeEvents(stage, partial);
        }
      }
      resolveFinal(accumulated);
    } catch (err) {
      rejectFinal(err);
      yield {
        type: "error",
        code: "runtime_error",
        message: (err as Error).message,
        stage: "execute-query",
      };
    }
  }

  return { events: events(), final };
}

function* expandNodeEvents(
  stage: GraphStage | undefined,
  partial: Partial<GraphStateType>,
): Generator<QueryEvent> {
  if (!stage) return;
  if (partial.intent) yield { type: "intent", intent: partial.intent };
  if (partial.ragContext) {
    yield {
      type: "rag",
      citations: partial.ragContext.citations,
      snippetCount: partial.ragContext.snippets.length,
    };
  }
  if (partial.sql && partial.sqlMeta) {
    yield {
      type: "sql",
      sql: partial.sql,
      confidence: partial.sqlMeta.confidence,
      assumptions: partial.sqlMeta.assumptions,
      citations: partial.sqlMeta.citations,
      clarificationNeeded: partial.sqlMeta.clarificationNeeded,
    };
  }
  if (partial.accessDecision) {
    yield { type: "access", decision: partial.accessDecision };
  }
  if (partial.results) yield { type: "results", results: partial.results };
  if (partial.chart) yield { type: "chart", chart: partial.chart };
  if (partial.error) {
    yield {
      type: "error",
      code: partial.error.code,
      message: partial.error.message,
      stage: partial.error.stage,
    };
  }
}
