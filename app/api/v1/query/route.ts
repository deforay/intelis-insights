/**
 * POST /api/v1/query
 *
 * The single streaming endpoint that drives the chat experience.
 * Authenticates the user, resolves or creates a session, runs the
 * LangGraph workflow, and streams NDJSON `QueryEvent`s as nodes
 * complete. Writes one audit row and one assistant message after the
 * stream finishes (success or fail).
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { auth } from "@/auth";
import { userContextFromSession } from "@/lib/auth/rbac";
import {
  buildConversationBlock,
  createSession,
  getSessionForUser,
  recordAssistantMessage,
  recordUserMessage,
} from "@/lib/chat/sessions";
import { runQuery } from "@/lib/graph/runner";
import type { QueryEvent } from "@/lib/graph/events";
import { writeAuditLog } from "@/lib/audit/log";
import { flushTraces } from "@/lib/observability/langfuse";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const BodySchema = z.object({
  question: z.string().trim().min(1, "question is required"),
  sessionId: z.uuid().optional(),
});

export async function POST(req: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const raw = await req.json().catch(() => null);
  const parsed = BodySchema.safeParse(raw);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "invalid request", details: parsed.error.issues },
      { status: 400 },
    );
  }
  const { question, sessionId: providedSessionId } = parsed.data;
  const userCtx = userContextFromSession(session);

  // Resolve or create session
  let sessionId: string;
  if (providedSessionId) {
    const existing = await getSessionForUser({
      sessionId: providedSessionId,
      userId: userCtx.userId,
    });
    if (!existing) {
      return NextResponse.json({ error: "session not found" }, { status: 404 });
    }
    sessionId = existing.id;
  } else {
    sessionId = await createSession({
      userId: userCtx.userId,
      title: question,
    });
  }

  const conversationBlock = await buildConversationBlock(sessionId);
  await recordUserMessage({ sessionId, content: question });

  const startedAt = Date.now();
  const { events, final } = await runQuery({
    question,
    sessionId,
    userContext: userCtx,
    conversationBlock,
  });

  const encoder = new TextEncoder();
  const stream = new ReadableStream<Uint8Array>({
    async start(controller) {
      const writeEvent = (event: QueryEvent) => {
        controller.enqueue(encoder.encode(`${JSON.stringify(event)}\n`));
      };

      let lastGeneratedSql: string | null = null;
      let finalState: Awaited<typeof final> | null = null;
      let errored = false;

      try {
        for await (const event of events) {
          if (event.type === "sql") lastGeneratedSql = event.sql;
          writeEvent(event);
        }
        finalState = await final;
      } catch (err) {
        errored = true;
        writeEvent({
          type: "error",
          code: "runtime_error",
          message: (err as Error).message,
          stage: "execute-query",
        });
      }

      const durationMs = Date.now() - startedAt;
      let auditId: string | null = null;

      if (finalState && !errored) {
        try {
          auditId = await writeAuditLog({
            question,
            state: finalState,
            generatedSql: lastGeneratedSql,
            durationMs,
          });
          await recordAssistantMessage({
            sessionId,
            content: buildAssistantContent(finalState),
            queryResult: finalState.results,
            chart: finalState.chart,
          });
        } catch (err) {
          writeEvent({
            type: "error",
            code: "audit_write_failed",
            message: (err as Error).message,
            stage: "format-response",
          });
        }
      }

      writeEvent({ type: "done", auditId, durationMs });
      controller.close();

      // Best-effort flush so traces from this request land in LangFuse
      // before the process is recycled. Non-fatal on failure.
      flushTraces().catch((err) => {
        console.error("[langfuse] flush failed:", err);
      });
    },
  });

  return new Response(stream, {
    headers: {
      "Content-Type": "application/x-ndjson; charset=utf-8",
      "Cache-Control": "no-cache, no-transform",
      "X-Accel-Buffering": "no",
    },
  });
}

function buildAssistantContent(state: import("@/lib/graph/state").GraphStateType): string {
  if (state.error) return `Error: ${state.error.message}`;

  const parts: string[] = [];

  // Lead with the natural-language summary if we have one.
  if (state.narration) parts.push(state.narration);

  // Result shape — useful for follow-ups even when the narration is rich.
  if (state.results) {
    if (state.results.count === 1 && state.results.columns.length === 1) {
      const v = state.results.rows[0][state.results.columns[0]];
      parts.push(`Result: ${state.results.columns[0]} = ${String(v)}`);
    } else {
      parts.push(
        `Result shape: ${state.results.count} row(s) over columns [${state.results.columns.join(", ")}]`,
      );
    }
  }

  // The assumptions the model applied — preserve them across turns so a
  // follow-up doesn't silently change the convention.
  if (state.sqlMeta?.assumptions?.length) {
    parts.push(
      `Assumptions: ${state.sqlMeta.assumptions.map((a) => `- ${a}`).join(" ")}`,
    );
  }

  // The SQL itself is the source of truth for filter / join conventions.
  // buildConversationBlock scrubs PII before this re-enters the LLM prompt.
  if (state.sql) parts.push(`SQL: ${state.sql}`);

  return parts.join("\n");
}
