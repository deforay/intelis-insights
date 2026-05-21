"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { ProgressSteps } from "./progress-steps";
import { ErrorCard } from "./error-card";
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
  const hasAnyContent = hasResults || hasError;

  return (
    <div className="flex flex-col gap-3">
      {/* Streaming progress — only visible until results arrive */}
      {(turn.isStreaming || Object.keys(turn.stages).length > 0) &&
        !hasAnyContent && (
          <ProgressSteps stages={turn.stages} isStreaming={turn.isStreaming} />
        )}

      {/* Streaming skeleton while we wait for results */}
      {turn.isStreaming && !hasAnyContent && (
        <Skeleton className="h-[180px] w-full rounded-2xl" />
      )}

      {/* Error path */}
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
