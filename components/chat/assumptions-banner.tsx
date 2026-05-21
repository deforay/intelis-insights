"use client";

import { Lightbulb } from "lucide-react";

export function AssumptionsBanner({ assumptions }: { assumptions: string[] }) {
  if (assumptions.length === 0) return null;
  return (
    <div className="rounded-lg border border-dashed bg-muted/20 p-3">
      <div className="flex items-center gap-1.5 mb-2 text-xs font-medium text-muted-foreground">
        <Lightbulb className="size-3.5" />
        Assumptions
      </div>
      <ul className="flex flex-col gap-1 text-xs text-foreground/80">
        {assumptions.map((a, i) => (
          <li key={i} className="flex gap-1.5">
            <span className="text-muted-foreground">•</span>
            <span>{a}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
