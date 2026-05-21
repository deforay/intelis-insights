"use client";

import { useState } from "react";
import { ChevronDown, Code2, Copy, Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

export function SqlBlock({
  sql,
  confidence,
  rewritten,
}: {
  sql: string;
  confidence: number | null;
  rewritten?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);

  const copy = () => {
    navigator.clipboard.writeText(sql);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  };

  return (
    <div className="rounded-lg border bg-card">
      <div className="flex items-center gap-2 px-3 py-2 border-b">
        <Code2 className="size-3.5 text-muted-foreground" />
        <span className="text-xs font-medium">
          {rewritten ? "Generated SQL (RBAC-scoped)" : "Generated SQL"}
        </span>
        {confidence !== null && (
          <Badge variant="secondary" className="text-[10px] font-normal">
            {Math.round(confidence * 100)}% confidence
          </Badge>
        )}
        <div className="ml-auto flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon-xs"
            onClick={copy}
            aria-label="Copy SQL"
          >
            {copied ? (
              <Check className="size-3 text-primary" />
            ) : (
              <Copy className="size-3" />
            )}
          </Button>
          <Button
            variant="ghost"
            size="icon-xs"
            onClick={() => setOpen((v) => !v)}
            aria-label="Toggle SQL"
          >
            <ChevronDown
              className={cn(
                "size-3 transition-transform",
                open && "rotate-180",
              )}
            />
          </Button>
        </div>
      </div>
      {open && (
        <pre className="overflow-x-auto p-3 text-[11px] leading-relaxed font-mono text-foreground/90">
          {sql}
        </pre>
      )}
    </div>
  );
}
