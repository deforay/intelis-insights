"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { RefreshCw, Pin, PinOff, ExternalLink } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface ReportCardProps {
  id: string;
  title: string;
  question: string;
  pinned: boolean;
  lastSummary: {
    rowCount: number;
    executionMs: number;
    scalarValue?: number | null;
    firstColumn?: string;
  } | null;
  lastRunAt: string | null;
}

export function ReportCard({
  id,
  title,
  question,
  pinned,
  lastSummary,
  lastRunAt,
}: ReportCardProps) {
  const router = useRouter();
  const [refreshing, setRefreshing] = useState(false);
  const [optimisticValue, setOptimisticValue] = useState<number | null>(
    lastSummary?.scalarValue ?? null,
  );

  const refresh = async () => {
    setRefreshing(true);
    try {
      const res = await fetch(`/api/v1/reports/${id}/refresh`, {
        method: "POST",
      });
      const body = await res.json();
      if (!res.ok) throw new Error(body.error ?? `HTTP ${res.status}`);
      const result = body.result as {
        columns: string[];
        rows: Record<string, unknown>[];
        count: number;
      };
      // Optimistically update the visible KPI
      if (result.count === 1 && result.rows[0]) {
        for (const col of result.columns) {
          const v = result.rows[0][col];
          if (typeof v === "number") {
            setOptimisticValue(v);
            break;
          }
        }
      }
      toast.success("Refreshed", { description: title });
      router.refresh();
    } catch (err) {
      toast.error("Refresh failed", {
        description: (err as Error).message,
      });
    } finally {
      setRefreshing(false);
    }
  };

  const togglePin = async () => {
    try {
      const res = await fetch(`/api/v1/reports/${id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ pinned: !pinned }),
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error ?? `HTTP ${res.status}`);
      }
      toast.success(pinned ? "Unpinned" : "Pinned to dashboard");
      router.refresh();
    } catch (err) {
      toast.error("Action failed", { description: (err as Error).message });
    }
  };

  const value = optimisticValue;
  const tsLabel = lastRunAt
    ? new Date(lastRunAt).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "—";

  return (
    <div className="group relative rounded-2xl border bg-card/60 backdrop-blur p-5 flex flex-col gap-4 hover:border-foreground/15 transition-colors overflow-hidden">
      <div
        className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/60 to-transparent"
      />

      <div className="flex items-start justify-between gap-2 min-w-0">
        <div className="min-w-0 flex-1">
          <div className="text-sm font-semibold truncate">{title}</div>
          <div className="text-[11px] text-muted-foreground line-clamp-2 mt-0.5">
            {question}
          </div>
        </div>
        <Button
          variant="ghost"
          size="icon-xs"
          onClick={togglePin}
          aria-label={pinned ? "Unpin" : "Pin to dashboard"}
        >
          {pinned ? (
            <Pin className="size-3.5 text-primary" />
          ) : (
            <PinOff className="size-3.5 text-muted-foreground" />
          )}
        </Button>
      </div>

      <div className="flex-1">
        {value !== null && value !== undefined ? (
          <div>
            {lastSummary?.firstColumn && (
              <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                {prettyLabel(lastSummary.firstColumn)}
              </div>
            )}
            <div className="mt-1 text-4xl font-semibold tracking-tight tabular-nums bg-gradient-to-b from-foreground to-foreground/70 bg-clip-text text-transparent">
              {value.toLocaleString()}
            </div>
          </div>
        ) : lastSummary ? (
          <div>
            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
              Rows
            </div>
            <div className="mt-1 text-4xl font-semibold tracking-tight tabular-nums">
              {lastSummary.rowCount.toLocaleString()}
            </div>
          </div>
        ) : (
          <div className="text-xs text-muted-foreground">No data yet — refresh to run.</div>
        )}
      </div>

      <div className="flex items-center justify-between gap-2 text-[10px] text-muted-foreground">
        <span>Updated {tsLabel}</span>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon-xs"
            onClick={refresh}
            disabled={refreshing}
            aria-label="Refresh"
          >
            <RefreshCw
              className={cn("size-3.5", refreshing && "animate-spin")}
            />
          </Button>
          <Link
            href={`/reports/${id}`}
            prefetch={false}
            className="inline-flex size-6 items-center justify-center rounded-md hover:bg-muted/50 text-muted-foreground hover:text-foreground transition-colors"
            aria-label="Open report"
          >
            <ExternalLink className="size-3" />
          </Link>
        </div>
      </div>
    </div>
  );
}

function prettyLabel(name: string): string {
  if (/\s/.test(name)) return name;
  return name
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((p) => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase())
    .join(" ");
}
