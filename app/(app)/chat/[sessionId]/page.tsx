import { auth } from "@/auth";
import { notFound, redirect } from "next/navigation";
import { Topbar } from "@/components/app-shell/topbar";
import { ChatClient } from "@/components/chat/chat-client";
import {
  getSessionForUser,
  listSessionAuditRows,
  listMessages,
} from "@/lib/chat/sessions";
import type {
  AssistantTurn,
  ChatTurn,
  UserTurn,
} from "@/components/chat/types";
import type {
  AccessDecision,
  ChartSuggestion,
  GraphStage,
  LabQueryResult,
} from "@/lib/graph/types";

export const dynamic = "force-dynamic";

export default async function ResumeChatPage({
  params,
}: {
  params: Promise<{ sessionId: string }>;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const { sessionId } = await params;
  const chatSession = await getSessionForUser({
    sessionId,
    userId: session.user.id,
  });
  if (!chatSession) notFound();

  const [messages, auditRows] = await Promise.all([
    listMessages(sessionId),
    listSessionAuditRows(sessionId),
  ]);
  const turns = buildTurns(messages, auditRows);

  return (
    <>
      <Topbar
        session={session}
        title={chatSession.title ?? "Conversation"}
      />
      <ChatClient
        initialSessionId={sessionId}
        initialMessages={turns}
      />
    </>
  );
}

type ChatMessageRow = Awaited<ReturnType<typeof listMessages>>[number];
type SessionAuditRow = Awaited<ReturnType<typeof listSessionAuditRows>>[number];

function buildTurns(
  messages: ChatMessageRow[],
  auditRows: SessionAuditRow[],
): ChatTurn[] {
  const auditsByQuestion = buildAuditQueues(auditRows);
  let previousQuestion: string | null = null;

  return messages.map((m) => {
    const createdAt = new Date(m.createdAt).getTime();
    if (m.role === "user") {
      previousQuestion = m.content;
      const t: UserTurn = {
        id: m.id,
        role: "user",
        content: m.content,
        createdAt,
      };
      return t;
    }
    const audit = previousQuestion
      ? auditsByQuestion.get(previousQuestion)?.shift()
      : null;
    const validation = parseValidationResult(audit?.validationResult);
    const errorStage = parseGraphStage(audit?.errorStage);
    const t: AssistantTurn = {
      id: m.id,
      role: "assistant",
      createdAt,
      intent: null,
      citationCount: 0,
      sql: audit?.generatedSql ?? null,
      sqlConfidence: validation?.confidence ?? null,
      assumptions: validation?.assumptions ?? [],
      citations: validation?.citations ?? [],
      clarificationNeeded: null,
      accessDecision: (audit?.accessDecision as AccessDecision | null) ?? null,
      results: (m.queryResult as LabQueryResult | null) ?? null,
      narration: audit?.errorStage ? null : extractNarration(m.content),
      followUps: [],
      clarification: null,
      chart: (m.chart as ChartSuggestion | null) ?? null,
      error: errorStage
        ? {
            code: "stored_error",
            message:
              "The query workflow hit an error. Open the audit log for details.",
            stage: errorStage,
          }
        : null,
      traceId: audit?.traceId ?? null,
      durationMs: audit?.durationMs ?? null,
      stages: {
        "parse-question": true,
        "retrieve-context": true,
        "generate-sql": true,
        "validate-access": true,
        "validate-query": true,
        "execute-query": true,
        "format-response": true,
      },
      isStreaming: false,
    };
    return t;
  });
}

function buildAuditQueues(rows: SessionAuditRow[]): Map<string, SessionAuditRow[]> {
  const queues = new Map<string, SessionAuditRow[]>();
  for (const row of rows) {
    const queue = queues.get(row.question) ?? [];
    queue.push(row);
    queues.set(row.question, queue);
  }
  return queues;
}

function extractNarration(content: string): string | null {
  const trimmed = content.trim();
  if (!trimmed || trimmed.startsWith("Error:")) return null;

  const [firstBlock] = trimmed.split(/\n(?=Result:|Result shape:|Assumptions:|SQL:)/);
  return firstBlock.trim() || null;
}

function parseValidationResult(value: unknown): {
  confidence: number | null;
  assumptions: string[];
  citations: string[];
} | null {
  if (!isRecord(value)) return null;
  return {
    confidence:
      typeof value.confidence === "number" ? value.confidence : null,
    assumptions: stringArray(value.assumptions),
    citations: stringArray(value.citations),
  };
}

function parseGraphStage(value: unknown): GraphStage | null {
  if (typeof value !== "string") return null;
  const valid = new Set<GraphStage>([
    "parse-question",
    "retrieve-context",
    "generate-sql",
    "validate-access",
    "validate-query",
    "execute-query",
    "narrate-result",
    "format-response",
  ]);
  return valid.has(value as GraphStage) ? (value as GraphStage) : null;
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string")
    : [];
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}
