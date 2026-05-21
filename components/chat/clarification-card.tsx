"use client";

import { MessageCircleQuestion } from "lucide-react";

export function ClarificationCard({
  question,
  reason,
}: {
  question: string;
  reason?: string;
}) {
  return (
    <div className="relative rounded-2xl border bg-card/50 backdrop-blur p-5 overflow-hidden">
      <div
        className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/70 to-transparent"
      />
      <div className="flex items-start gap-3">
        <div className="relative shrink-0 mt-0.5">
          <div className="absolute inset-0 rounded-full bg-primary/25 blur-md" />
          <div className="relative flex size-8 items-center justify-center rounded-full bg-gradient-to-br from-primary/80 to-primary/50 text-primary-foreground">
            <MessageCircleQuestion className="size-4" />
          </div>
        </div>
        <div className="flex flex-col gap-1.5 min-w-0 flex-1">
          <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
            I need a bit more
          </div>
          <p className="text-[15px] leading-relaxed text-foreground/90">
            {question}
          </p>
          {reason && (
            <p className="text-[11px] text-muted-foreground/70 mt-1">
              {reason}
            </p>
          )}
          <p className="text-[11px] text-muted-foreground/80 mt-2">
            Reply in the composer below with your refined question.
          </p>
        </div>
      </div>
    </div>
  );
}
