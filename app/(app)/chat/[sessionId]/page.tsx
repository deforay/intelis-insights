import { auth } from "@/auth";
import { notFound, redirect } from "next/navigation";
import { Topbar } from "@/components/app-shell/topbar";
import { ChatClient } from "@/components/chat/chat-client";
import {
  getSessionForUser,
  listMessages,
} from "@/lib/chat/sessions";
import type {
  AssistantTurn,
  ChatTurn,
  UserTurn,
} from "@/components/chat/types";
import type { ChartSuggestion, LabQueryResult } from "@/lib/graph/types";

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

  const messages = await listMessages(sessionId);
  const turns: ChatTurn[] = messages.map((m) => {
    const createdAt = new Date(m.createdAt).getTime();
    if (m.role === "user") {
      const t: UserTurn = {
        id: m.id,
        role: "user",
        content: m.content,
        createdAt,
      };
      return t;
    }
    const t: AssistantTurn = {
      id: m.id,
      role: "assistant",
      createdAt,
      intent: null,
      citationCount: 0,
      sql: null,
      sqlConfidence: null,
      assumptions: [],
      citations: [],
      clarificationNeeded: null,
      accessDecision: null,
      results: (m.queryResult as LabQueryResult | null) ?? null,
      chart: (m.chart as ChartSuggestion | null) ?? null,
      error: null,
      traceId: null,
      durationMs: null,
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
