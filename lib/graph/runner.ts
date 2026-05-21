/**
 * Streaming runner for the LangGraph workflow.
 *
 * Wraps `graph.stream()` and converts LangGraph's `{nodeName: update}`
 * payloads into typed `QueryEvent`s the API surface can serialise to
 * NDJSON. Also accumulates the final state so the route handler can
 * write a single audit row after the stream completes.
 *
 * LangFuse instrumentation: when LANGFUSE_* env is configured, we
 * create one trace per query with a span per node and a `generation`
 * event for the SQL-gen LLM call (with token usage). Otherwise the
 * instrumentation calls are no-ops.
 */
import { randomUUID } from "node:crypto";
import { getGraph } from "./workflow";
import type { QueryEvent } from "./events";
import type { GraphStateType } from "./state";
import type { GraphStage, UserContext } from "./types";
import { getLangfuse } from "@/lib/observability/langfuse";
import { env } from "@/lib/config/env";

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
  "narrate-result": "narrate-result",
  "format-response": "format-response",
};

type LangfuseSpan = ReturnType<NonNullable<ReturnType<typeof getLangfuse>>["span"]>;
type LangfuseTrace = ReturnType<NonNullable<ReturnType<typeof getLangfuse>>["trace"]>;

export async function runQuery(input: RunQueryInput): Promise<RunQueryResult> {
  const graph = await getGraph();
  const traceId = randomUUID();
  const startedAt = Date.now();

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
    narration: null,
    followUpSuggestions: null,
    chart: null,
    error: null,
    sqlRetries: 0,
    traceId,
    startedAt,
  };

  let resolveFinal!: (s: GraphStateType) => void;
  let rejectFinal!: (e: unknown) => void;
  const final = new Promise<GraphStateType>((res, rej) => {
    resolveFinal = res;
    rejectFinal = rej;
  });

  const langfuse = getLangfuse();
  const trace: LangfuseTrace | null = langfuse
    ? langfuse.trace({
        id: traceId,
        name: "query",
        userId: input.userContext.userId,
        sessionId: input.sessionId,
        input: { question: input.question },
        metadata: {
          accessLevel: input.userContext.accessLevel,
          allowedProvinces: input.userContext.allowedProvinces,
          allowedDistricts: input.userContext.allowedDistricts,
          llmProvider: env.LLM_PROVIDER,
        },
      })
    : null;

  async function* events(): AsyncGenerator<QueryEvent> {
    yield { type: "session", sessionId: input.sessionId, traceId };
    const accumulated = { ...initial } as GraphStateType;
    const spans = new Map<string, LangfuseSpan>();
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
          if (stage) {
            yield { type: "stage", stage };
            const span = trace?.span({
              name: stage,
              startTime: new Date(),
            });
            if (span) spans.set(stage, span);
          }
          Object.assign(accumulated, partial);
          recordToLangfuse(trace, stage, partial);
          // Close the span when its node emits an update — node-level
          // streamMode "updates" emits once per completed node, so this
          // marks the end of that node's work.
          if (stage && spans.has(stage)) {
            spans.get(stage)!.end({
              output: summariseStateForTrace(partial),
            });
          }
          yield* expandNodeEvents(stage, partial);
        }
      }
      trace?.update({
        output: accumulated.results
          ? {
              rowCount: accumulated.results.count,
              executionMs: accumulated.results.executionMs,
              durationMs: Date.now() - startedAt,
            }
          : { error: accumulated.error },
      });
      resolveFinal(accumulated);
    } catch (err) {
      console.error("[graph] uncaught error in stream:", err);
      trace?.update({
        output: { error: (err as Error).message },
      });
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

function recordToLangfuse(
  trace: LangfuseTrace | null,
  stage: GraphStage | undefined,
  partial: Partial<GraphStateType>,
): void {
  if (!trace || !stage) return;
  if (stage === "generate-sql" && partial.sqlMeta) {
    trace.generation({
      name: "generate-sql",
      model: partial.sqlMeta.modelId,
      output: partial.sql ?? "",
      usage: {
        input: partial.sqlMeta.tokenUsage.inputTokens ?? undefined,
        output: partial.sqlMeta.tokenUsage.outputTokens ?? undefined,
        total: partial.sqlMeta.tokenUsage.totalTokens ?? undefined,
      },
      metadata: {
        confidence: partial.sqlMeta.confidence,
        assumptions: partial.sqlMeta.assumptions,
        citations: partial.sqlMeta.citations,
        clarificationNeeded: partial.sqlMeta.clarificationNeeded,
      },
    });
  }
}

function summariseStateForTrace(
  partial: Partial<GraphStateType>,
): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  if (partial.intent) out.intent = partial.intent;
  if (partial.ragContext)
    out.ragSnippetCount = partial.ragContext.snippets.length;
  if (partial.sql) out.sqlLength = partial.sql.length;
  if (partial.accessDecision) out.accessDecision = partial.accessDecision;
  if (partial.results) out.rowCount = partial.results.count;
  if (partial.chart) out.chart = partial.chart.recommended;
  if (partial.error) out.error = partial.error;
  return out;
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
  if (partial.narration) {
    yield {
      type: "narration",
      narration: partial.narration,
      followUps: partial.followUpSuggestions ?? [],
    };
  }
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
