"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { ProgressSteps } from "./progress-steps";
import { ErrorCard } from "./error-card";
import { ClarificationCard } from "./clarification-card";
import { BentoResponse } from "./bento-response";
import type { AssistantTurn } from "./types";

export function AssistantBubble({
  turn,
  question,
  onPickFollowUp,
}: {
  turn: AssistantTurn;
  /** The user question that produced this turn. Threaded down for save-to-dashboard. */
  question?: string;
  onPickFollowUp?: (question: string) => void;
}) {
  const hasResults = !!turn.results;
  const hasError = !!turn.error;
  const hasClarification = !!turn.clarification;
  const hasAnyContent = hasResults || hasError || hasClarification;

  return (
    <div className="flex flex-col gap-3">
      {(turn.isStreaming || Object.keys(turn.stages).length > 0) &&
        !hasAnyContent && (
          <ProgressSteps stages={turn.stages} isStreaming={turn.isStreaming} />
        )}

      {turn.isStreaming && !hasAnyContent && (
        <Skeleton className="h-[180px] w-full rounded-2xl" />
      )}

      {hasClarification && turn.clarification && (
        <ClarificationCard
          question={turn.clarification.question}
          reason={turn.clarification.reason}
        />
      )}

      {hasError && turn.error && (
        <ErrorCard
          code={turn.error.code}
          message={turn.error.message}
          stage={turn.error.stage}
          traceId={turn.traceId}
        />
      )}

      {hasResults && (
        <BentoResponse
          turn={turn}
          question={question}
          onPickFollowUp={onPickFollowUp}
        />
      )}
    </div>
  );
}
