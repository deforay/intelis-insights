"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { ProgressSteps } from "./progress-steps";
import { ErrorCard } from "./error-card";
import { ClarificationCard } from "./clarification-card";
import { BentoResponse } from "./bento-response";
import type { AssistantTurn } from "./types";

export function AssistantBubble({
  turn,
  onPickFollowUp,
}: {
  turn: AssistantTurn;
  onPickFollowUp?: (question: string) => void;
}) {
  const hasResults = !!turn.results;
  const hasError = !!turn.error;
  const hasClarification = !!turn.clarification;
  const hasAnyContent = hasResults || hasError || hasClarification;

  return (
    <div className="flex flex-col gap-3">
      {/* Streaming progress — only visible until something arrives */}
      {(turn.isStreaming || Object.keys(turn.stages).length > 0) &&
        !hasAnyContent && (
          <ProgressSteps stages={turn.stages} isStreaming={turn.isStreaming} />
        )}

      {/* Streaming skeleton while we wait for results */}
      {turn.isStreaming && !hasAnyContent && (
        <Skeleton className="h-[180px] w-full rounded-2xl" />
      )}

      {/* Clarification — model asked back. Friendly card, not red banner. */}
      {hasClarification && turn.clarification && (
        <ClarificationCard
          question={turn.clarification.question}
          reason={turn.clarification.reason}
        />
      )}

      {/* Error — something actually broke */}
      {hasError && turn.error && (
        <ErrorCard
          code={turn.error.code}
          message={turn.error.message}
          stage={turn.error.stage}
        />
      )}

      {/* Bento response — recomposes as data streams in */}
      {hasResults && (
        <BentoResponse turn={turn} onPickFollowUp={onPickFollowUp} />
      )}
    </div>
  );
}
