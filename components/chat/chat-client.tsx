"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from "react";
import { toast } from "sonner";
import { ArrowRight, RefreshCw, Sparkles } from "lucide-react";
import type { QueryEvent } from "@/lib/graph/events";
import { parseNdjsonStream } from "@/lib/client/stream";
import { cn } from "@/lib/utils";
import { Composer } from "./composer";
import { UserBubble } from "./user-bubble";
import { AssistantBubble } from "./assistant-bubble";
import {
  createAssistantTurn,
  createUserTurn,
  type AssistantTurn,
  type ChatTurn,
} from "./types";
import {
  DEFAULT_EMPTY_STATE_SUGGESTIONS,
  getEmptyStateSuggestions,
  getSuggestionCategory,
  type SuggestionCategory,
} from "./suggestions";

const subscribeToSuggestionClock = () => () => {};
const getDefaultSuggestionSnapshot = () =>
  DEFAULT_EMPTY_STATE_SUGGESTIONS.join("\n");
let browserSuggestionSnapshot: string | null = null;

function getSuggestionSnapshot() {
  browserSuggestionSnapshot ??= getEmptyStateSuggestions().join("\n");
  return browserSuggestionSnapshot;
}

export function ChatClient({
  initialSessionId,
  initialMessages,
}: {
  initialSessionId?: string;
  initialMessages?: ChatTurn[];
}) {
  const [sessionId, setSessionId] = useState<string | null>(
    initialSessionId ?? null,
  );
  const [turns, setTurns] = useState<ChatTurn[]>(initialMessages ?? []);
  const [draft, setDraft] = useState("");
  const [isStreaming, setIsStreaming] = useState(false);
  const scrollRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    scrollRef.current?.scrollTo({
      top: scrollRef.current.scrollHeight,
      behavior: "smooth",
    });
  }, [turns]);

  const submit = useCallback(
    async (question: string) => {
      if (!question.trim() || isStreaming) return;
      setDraft("");
      setIsStreaming(true);

      const userTurn = createUserTurn(question);
      const assistantTurn = createAssistantTurn();
      setTurns((prev) => [...prev, userTurn, assistantTurn]);

      const update = (patch: Partial<AssistantTurn>) =>
        setTurns((prev) =>
          prev.map((t) =>
            t.id === assistantTurn.id && t.role === "assistant"
              ? { ...t, ...patch }
              : t,
          ),
        );

      const stageStart = Date.now();
      try {
        const res = await fetch("/api/v1/query", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            question,
            sessionId: sessionId ?? undefined,
          }),
        });
        if (!res.ok || !res.body) {
          const errBody = await res.json().catch(() => null);
          throw new Error(errBody?.error ?? `HTTP ${res.status}`);
        }

        const stages: Record<string, boolean> = {};
        let prevStage: string | null = null;
        for await (const event of parseNdjsonStream<QueryEvent>(res.body)) {
          switch (event.type) {
            case "session":
              if (!sessionId) {
                setSessionId(event.sessionId);
                window.history.pushState(
                  null,
                  "",
                  `/chat/${encodeURIComponent(event.sessionId)}`,
                );
              }
              update({ traceId: event.traceId });
              break;
            case "stage":
              if (prevStage) stages[prevStage] = true;
              prevStage = event.stage;
              update({ stages: { ...stages } });
              break;
            case "intent":
              update({ intent: event.intent });
              break;
            case "rag":
              update({ citationCount: event.snippetCount });
              break;
            case "sql":
              update({
                sql: event.sql,
                sqlConfidence: event.confidence,
                assumptions: event.assumptions,
                citations: event.citations,
                clarificationNeeded: event.clarificationNeeded,
              });
              break;
            case "access":
              update({ accessDecision: event.decision });
              break;
            case "results":
              update({ results: event.results });
              break;
            case "narration":
              update({
                narration: event.narration,
                followUps: event.followUps,
              });
              break;
            case "clarification":
              update({
                clarification: {
                  question: event.question,
                  reason: event.reason,
                },
              });
              break;
            case "chart":
              update({ chart: event.chart });
              break;
            case "error":
              update({
                error: {
                  code: event.code,
                  message: event.message,
                  stage: event.stage,
                },
              });
              break;
            case "done":
              if (prevStage) stages[prevStage] = true;
              update({
                stages: { ...stages },
                isStreaming: false,
                durationMs: event.durationMs,
              });
              break;
          }
        }
      } catch (err) {
        const message = (err as Error).message;
        toast.error("Query failed", { description: message });
        update({
          isStreaming: false,
          error: {
            code: "client_error",
            message,
            stage: "execute-query",
          },
          durationMs: Date.now() - stageStart,
        });
      } finally {
        setIsStreaming(false);
      }
    },
    [isStreaming, sessionId],
  );

  const isEmpty = turns.length === 0;

  return (
    <div className="flex flex-1 flex-col min-h-0">
      <div
        ref={scrollRef}
        className="flex-1 overflow-y-auto"
      >
        <div
          className={cn(
            "mx-auto w-full px-6 md:px-10 lg:px-14 py-8",
            isEmpty ? "max-w-5xl" : "max-w-7xl",
          )}
        >
          {isEmpty ? (
            <EmptyState onPick={submit} />
          ) : (
            <div className="flex flex-col gap-8">
              {turns.map((t, idx) => {
                if (t.role === "user")
                  return <UserBubble key={t.id} content={t.content} />;
                const prior = turns[idx - 1];
                const q = prior?.role === "user" ? prior.content : undefined;
                return (
                  <AssistantBubble
                    key={t.id}
                    turn={t}
                    question={q}
                    onPickFollowUp={submit}
                  />
                );
              })}
            </div>
          )}
        </div>
      </div>

      <div className="border-t bg-background/80 backdrop-blur">
        <div
          className={cn(
            "mx-auto w-full px-6 md:px-10 lg:px-14 py-4",
            isEmpty ? "max-w-3xl" : "max-w-5xl",
          )}
        >
          <Composer
            value={draft}
            onChange={setDraft}
            onSubmit={() => submit(draft)}
            isStreaming={isStreaming}
            placeholder={
              isEmpty
                ? "Ask anything about your lab data…"
                : "Ask a follow-up…"
            }
          />
          <p className="mt-2 text-[10px] text-muted-foreground/70 text-center">
            Press Enter to send · Shift+Enter for newline · Results scoped to
            your access level
          </p>
        </div>
      </div>
    </div>
  );
}

function EmptyState({ onPick }: { onPick: (q: string) => void }) {
  const suggestionSnapshot = useSyncExternalStore(
    subscribeToSuggestionClock,
    getSuggestionSnapshot,
    getDefaultSuggestionSnapshot,
  );
  const [shuffledSnapshot, setShuffledSnapshot] = useState<string | null>(null);
  // Keep empty-state prompts curated and local; this avoids spending LLM calls
  // or exposing extra deployment context just to vary starter questions.
  const suggestions = (shuffledSnapshot ?? suggestionSnapshot).split("\n");

  const shuffleSuggestions = () => {
    setShuffledSnapshot(getEmptyStateSuggestions().join("\n"));
  };

  return (
    <div className="relative flex flex-col items-center justify-center h-full min-h-[56vh] gap-7 text-center">
      <div className="absolute inset-0 analytics-bg pointer-events-none" />

      <div className="relative">
        <div className="absolute inset-0 -m-3 rounded-full bg-primary/20 blur-2xl" />
        <div className="relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/60 text-primary-foreground brand-glow">
          <Sparkles className="size-6" />
        </div>
      </div>

      <div className="space-y-2 relative">
        <h2 className="text-2xl font-semibold tracking-tight bg-gradient-to-b from-foreground to-foreground/70 bg-clip-text text-transparent">
          Ask InteLIS
        </h2>
        <p className="text-sm text-muted-foreground max-w-md">
          Natural-language queries against your lab database. Streamed,
          scoped, audited.
        </p>
      </div>

      <div className="relative flex w-full max-w-2xl items-center justify-between px-1 text-left">
        <div>
          <div className="text-[10px] uppercase tracking-[0.22em] text-muted-foreground">
            Suggested questions
          </div>
        </div>
        <button
          type="button"
          onClick={shuffleSuggestions}
          className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border bg-background/70 px-2.5 py-1.5 text-xs text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40"
        >
          <RefreshCw className="size-3" />
          Shuffle
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 w-full max-w-2xl relative">
        {suggestions.map((s, i) => (
          <button
            key={s}
            onClick={() => onPick(s)}
            className="group relative min-h-24 cursor-pointer rounded-xl border bg-card/70 backdrop-blur px-4 py-3.5 text-left text-sm shadow-[0_1px_2px_rgba(15,23,42,0.03)] transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:bg-card hover:shadow-[0_10px_28px_rgba(15,23,42,0.07)] focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/40 overflow-hidden"
          >
            <span
              className="absolute -inset-px rounded-xl opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"
              style={{
                background:
                  "radial-gradient(ellipse 60% 80% at top, oklch(var(--brand) l c h / 0.15), transparent 70%)",
              }}
            />
            <span className="relative flex h-full flex-col justify-between gap-3">
              <span className="flex items-start gap-2">
                <span className="text-primary/70 text-xs mt-0.5">
                  {String(i + 1).padStart(2, "0")}
                </span>
                <span className="text-foreground/90 leading-snug">{s}</span>
              </span>
              <span className="flex items-center justify-between">
                <SuggestionPill category={getSuggestionCategory(s)} />
                <ArrowRight className="size-3.5 text-primary/0 transition-colors group-hover:text-primary/70" />
              </span>
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}

function SuggestionPill({ category }: { category: SuggestionCategory }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium",
        CATEGORY_STYLES[category],
      )}
    >
      {category}
    </span>
  );
}

const CATEGORY_STYLES: Record<SuggestionCategory, string> = {
  Volume: "border-primary/15 bg-primary/5 text-primary",
  Quality:
    "border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300",
  TAT:
    "border-teal-500/20 bg-teal-500/10 text-teal-700 dark:text-teal-300",
  Suppression:
    "border-fuchsia-500/20 bg-fuchsia-500/10 text-fuchsia-700 dark:text-fuchsia-300",
};
