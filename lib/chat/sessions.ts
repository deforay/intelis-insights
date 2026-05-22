/**
 * Chat session + message persistence.
 *
 * The LangGraph checkpointer holds the *runtime* conversation state
 * (used for re-entry into the graph). These tables hold the
 * *user-facing* transcript that the UI renders. They duplicate some
 * data, but keeping them separate means the audit log and admin views
 * never need to materialise checkpoint blobs.
 */
import { and, desc, eq } from "drizzle-orm";
import { db } from "@/lib/db/app";
import { auditLog, chatMessages, chatSessions } from "@/lib/db/schema";
import type { ChartSuggestion, LabQueryResult } from "@/lib/graph/types";

const CONVERSATION_BLOCK_TURNS = 4;
const TITLE_MAX_CHARS = 80;

export async function createSession(args: {
  userId: string;
  title: string;
}): Promise<string> {
  const [row] = await db
    .insert(chatSessions)
    .values({
      userId: args.userId,
      title: args.title.slice(0, TITLE_MAX_CHARS),
    })
    .returning({ id: chatSessions.id });
  return row.id;
}

export async function getSessionForUser(args: {
  sessionId: string;
  userId: string;
}) {
  const [row] = await db
    .select()
    .from(chatSessions)
    .where(
      and(
        eq(chatSessions.id, args.sessionId),
        eq(chatSessions.userId, args.userId),
      ),
    )
    .limit(1);
  return row ?? null;
}

export async function listSessions(userId: string) {
  return db
    .select()
    .from(chatSessions)
    .where(eq(chatSessions.userId, userId))
    .orderBy(desc(chatSessions.updatedAt));
}

export async function listMessages(sessionId: string) {
  return db
    .select()
    .from(chatMessages)
    .where(eq(chatMessages.sessionId, sessionId))
    .orderBy(chatMessages.createdAt);
}

export async function listSessionAuditRows(sessionId: string) {
  return db
    .select({
      id: auditLog.id,
      question: auditLog.question,
      generatedSql: auditLog.generatedSql,
      rewrittenSql: auditLog.rewrittenSql,
      accessDecision: auditLog.accessDecision,
      validationResult: auditLog.validationResult,
      durationMs: auditLog.durationMs,
      errorStage: auditLog.errorStage,
      errorMessage: auditLog.errorMessage,
      traceId: auditLog.traceId,
      createdAt: auditLog.createdAt,
    })
    .from(auditLog)
    .where(eq(auditLog.sessionId, sessionId))
    .orderBy(auditLog.createdAt);
}

export async function recordUserMessage(args: {
  sessionId: string;
  content: string;
}): Promise<void> {
  await db.insert(chatMessages).values({
    sessionId: args.sessionId,
    role: "user",
    content: args.content,
  });
}

export async function recordAssistantMessage(args: {
  sessionId: string;
  content: string;
  queryResult: LabQueryResult | null;
  chart: ChartSuggestion | null;
}): Promise<void> {
  await db.insert(chatMessages).values({
    sessionId: args.sessionId,
    role: "assistant",
    content: args.content,
    queryResult: args.queryResult ?? null,
    chart: args.chart ?? null,
  });
  await db
    .update(chatSessions)
    .set({ updatedAt: new Date() })
    .where(eq(chatSessions.id, args.sessionId));
}

/**
 * Build the prompt-friendly conversation block from the last few
 * turns of the session. Caller is responsible for PII-scrubbing
 * before sending to the LLM — see `lib/llm/scrub.ts`.
 */
export async function buildConversationBlock(
  sessionId: string,
): Promise<string | null> {
  const rows = await db
    .select({
      role: chatMessages.role,
      content: chatMessages.content,
      createdAt: chatMessages.createdAt,
    })
    .from(chatMessages)
    .where(eq(chatMessages.sessionId, sessionId))
    .orderBy(desc(chatMessages.createdAt))
    .limit(CONVERSATION_BLOCK_TURNS * 2);

  if (rows.length === 0) return null;
  const ordered = rows.slice().reverse();
  return ordered
    .map((r) => `${r.role === "user" ? "USER" : "ASSISTANT"}: ${r.content}`)
    .join("\n");
}
