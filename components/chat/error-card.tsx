"use client";

import Link from "next/link";
import {
  AlertTriangle,
  MessageCircleQuestion,
  ScrollText,
} from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function ErrorCard({
  code,
  message,
  stage,
  traceId,
}: {
  code: string;
  message: string;
  stage: string;
  traceId?: string | null;
}) {
  const isClarification = code === "clarification_needed";
  const auditHref = traceId
    ? `/admin/audit?traceId=${encodeURIComponent(traceId)}`
    : "/admin/audit?errors=1";

  return (
    <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
      <div className="flex items-start gap-2">
        {isClarification ? (
          <MessageCircleQuestion className="size-4 text-destructive mt-0.5 shrink-0" />
        ) : (
          <AlertTriangle className="size-4 text-destructive mt-0.5 shrink-0" />
        )}
        <div className="flex flex-1 flex-col gap-1">
          <div className="text-xs font-medium text-destructive">
            {isClarification ? "Clarification needed" : `Failed at ${stage}`}
          </div>
          <div className="text-xs text-foreground/80">{message}</div>
          {!isClarification && (
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <Link
                href={auditHref}
                className={cn(
                  buttonVariants({ variant: "outline", size: "xs" }),
                  "gap-1.5 bg-background/80",
                )}
              >
                <ScrollText className="size-3" />
                View audit log
              </Link>
              {traceId && (
                <span className="font-mono text-[10px] text-muted-foreground">
                  trace {traceId.slice(0, 8)}
                </span>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
