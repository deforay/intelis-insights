"use client";

import { Check, Loader2 } from "lucide-react";
import type { GraphStage } from "@/lib/graph/types";
import { cn } from "@/lib/utils";

const STAGE_LABELS: Array<{ key: GraphStage; label: string }> = [
  { key: "parse-question", label: "Understanding question" },
  { key: "retrieve-context", label: "Retrieving context" },
  { key: "generate-sql", label: "Generating SQL" },
  { key: "validate-access", label: "Checking access scope" },
  { key: "validate-query", label: "Validating SQL safety" },
  { key: "execute-query", label: "Running against InteLIS" },
  { key: "format-response", label: "Building response" },
];

export function ProgressSteps({
  stages,
  isStreaming,
}: {
  stages: Partial<Record<GraphStage, boolean>>;
  isStreaming: boolean;
}) {
  const firstPending = STAGE_LABELS.find((s) => !stages[s.key])?.key;
  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <ul className="flex flex-col gap-1.5">
        {STAGE_LABELS.map(({ key, label }) => {
          const done = stages[key] === true;
          const active = isStreaming && firstPending === key;
          return (
            <li
              key={key}
              className={cn(
                "flex items-center gap-2 text-xs",
                done
                  ? "text-foreground"
                  : active
                    ? "text-foreground"
                    : "text-muted-foreground/60",
              )}
            >
              <span className="flex size-4 items-center justify-center">
                {done ? (
                  <Check className="size-3 text-primary" />
                ) : active ? (
                  <Loader2 className="size-3 animate-spin text-primary" />
                ) : (
                  <span className="size-1.5 rounded-full bg-muted-foreground/30" />
                )}
              </span>
              <span>{label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
