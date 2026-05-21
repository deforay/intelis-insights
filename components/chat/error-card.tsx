"use client";

import { AlertTriangle, MessageCircleQuestion } from "lucide-react";

export function ErrorCard({
  code,
  message,
  stage,
}: {
  code: string;
  message: string;
  stage: string;
}) {
  const isClarification = code === "clarification_needed";
  return (
    <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
      <div className="flex items-start gap-2">
        {isClarification ? (
          <MessageCircleQuestion className="size-4 text-destructive mt-0.5 shrink-0" />
        ) : (
          <AlertTriangle className="size-4 text-destructive mt-0.5 shrink-0" />
        )}
        <div className="flex flex-col gap-1">
          <div className="text-xs font-medium text-destructive">
            {isClarification ? "Clarification needed" : `Failed at ${stage}`}
          </div>
          <div className="text-xs text-foreground/80">{message}</div>
        </div>
      </div>
    </div>
  );
}
