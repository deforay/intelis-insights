"use client";

import { Check } from "lucide-react";
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
    <div className="relative rounded-xl border bg-card/60 backdrop-blur p-3.5 overflow-hidden">
      <div
        className="absolute inset-x-0 top-0 h-px"
        style={{
          background:
            "linear-gradient(90deg, transparent, oklch(var(--brand) l c h / 0.6), transparent)",
        }}
      />
      <ul className="flex flex-col gap-2">
        {STAGE_LABELS.map(({ key, label }) => {
          const done = stages[key] === true;
          const active = isStreaming && firstPending === key;
          return (
            <li
              key={key}
              className={cn(
                "flex items-center gap-2.5 text-xs transition-colors",
                done
                  ? "text-foreground"
                  : active
                    ? "text-foreground"
                    : "text-muted-foreground/50",
              )}
            >
              <span className="flex size-4 items-center justify-center shrink-0">
                {done ? (
                  <span className="flex size-4 items-center justify-center rounded-full bg-primary/15">
                    <Check className="size-2.5 text-primary" />
                  </span>
                ) : active ? (
                  <span className="pulse-dot relative flex size-2 rounded-full bg-primary" />
                ) : (
                  <span className="size-1.5 rounded-full bg-muted-foreground/30" />
                )}
              </span>
              <span className={cn(active && "font-medium")}>{label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
