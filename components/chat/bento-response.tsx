"use client";

import {
  BarChart3,
  Code2,
  Lightbulb,
  Quote,
  Table2,
  Sigma,
  Activity,
  Database,
  ShieldCheck,
  Sparkles,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { ChartRenderer } from "@/components/chart/chart-renderer";
import { ResultTable } from "./result-table";
import { SaveReportButton } from "./save-report-button";
import type { AssistantTurn } from "./types";

/**
 * Asymmetric "bento" layout for an assistant turn's result.
 *
 * Tiles fill in as events stream: chart/table → KPI → assumptions
 * → SQL → citations → metadata. The grid recomposes based on what
 * data has arrived and the shape of the result (KPI vs table vs
 * chart-able).
 */
export function BentoResponse({
  turn,
  question,
  onPickFollowUp,
}: {
  turn: AssistantTurn;
  /** The user question that produced this turn — used for save-to-dashboard. */
  question?: string;
  onPickFollowUp?: (question: string) => void;
}) {
  const hasChart = !!turn.chart && turn.chart.recommended !== "table";
  const hasResults = !!turn.results;
  const isKpi =
    hasResults &&
    turn.results!.count === 1 &&
    turn.results!.columns.length <= 2;

  return (
    <div className="flex flex-col gap-4">
      {turn.narration && (
        <div className="relative rounded-2xl border bg-card/40 backdrop-blur p-5 overflow-hidden">
          <div
            className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/70 to-transparent"
          />
          <div className="flex items-start gap-3">
            <div className="relative shrink-0 mt-0.5">
              <div className="absolute inset-0 rounded-full bg-primary/30 blur-md" />
              <div className="relative flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-primary to-primary/70 text-primary-foreground">
                <Sparkles className="size-3.5" />
              </div>
            </div>
            <p className="text-[15px] leading-relaxed text-foreground/90">
              {turn.narration}
            </p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-12 gap-3 auto-rows-max">
      {/* Hero tile: chart, table, or KPI big-number */}
      {hasResults && (
        <BentoTile
          className={cn(
            "col-span-12",
            isKpi
              ? "lg:col-span-6"
              : hasChart
                ? "lg:col-span-8 row-span-2"
                : "lg:col-span-8",
          )}
          icon={hasChart ? <BarChart3 /> : isKpi ? <Sigma /> : <Table2 />}
          label={hasChart ? "Visualization" : isKpi ? "Result" : "Data"}
          accent="primary"
        >
          {isKpi ? (
            <KpiHero turn={turn} />
          ) : hasChart && turn.chart && turn.results ? (
            <>
              <ChartRenderer chart={turn.chart} result={turn.results} />
              {turn.chart.reasoning && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                  {turn.chart.reasoning}
                </p>
              )}
            </>
          ) : turn.results ? (
            <ResultTable result={turn.results} />
          ) : null}
        </BentoTile>
      )}

      {/* Result counters / quick stats */}
      {hasResults && (
        <BentoTile
          className={cn(
            "col-span-12 sm:col-span-6",
            isKpi ? "lg:col-span-6" : "lg:col-span-4",
          )}
          icon={<Activity />}
          label="Stats"
          accent="chart-2"
        >
          <Stats turn={turn} />
        </BentoTile>
      )}

      {/* Assumptions */}
      {turn.assumptions.length > 0 && (
        <BentoTile
          className="col-span-12 sm:col-span-6 lg:col-span-4"
          icon={<Lightbulb />}
          label="Assumptions"
          accent="chart-4"
        >
          <ul className="flex flex-col gap-1.5 text-xs">
            {turn.assumptions.map((a, i) => (
              <li key={i} className="flex gap-1.5">
                <span className="text-muted-foreground/60">·</span>
                <span className="text-foreground/80">{a}</span>
              </li>
            ))}
          </ul>
        </BentoTile>
      )}

      {/* Scope decision when injected */}
      {turn.accessDecision?.allowed &&
        turn.accessDecision.reason.startsWith("injected") && (
          <BentoTile
            className="col-span-12 sm:col-span-6 lg:col-span-4"
            icon={<ShieldCheck />}
            label="Access scope"
            accent="chart-3"
          >
            <p className="text-xs text-foreground/80">
              {turn.accessDecision.reason}
            </p>
          </BentoTile>
        )}

      {/* Generated SQL — wider tile */}
      {turn.sql && (
        <BentoTile
          className="col-span-12 lg:col-span-8"
          icon={<Code2 />}
          label="Generated SQL"
          accent="chart-5"
          headerExtra={
            turn.sqlConfidence !== null && (
              <Badge
                variant="secondary"
                className="font-mono font-normal text-[10px]"
              >
                {Math.round(turn.sqlConfidence * 100)}% confidence
              </Badge>
            )
          }
        >
          <pre className="overflow-x-auto text-[11px] leading-relaxed font-mono text-foreground/90 -mx-1 px-1">
            {turn.accessDecision?.rewrittenSql ?? turn.sql}
          </pre>
        </BentoTile>
      )}

      {/* Citations */}
      {turn.citations.length > 0 && (
        <BentoTile
          className="col-span-12 sm:col-span-6 lg:col-span-4"
          icon={<Quote />}
          label={`${turn.citations.length} citations`}
          accent="chart-1"
        >
          <div className="flex flex-wrap gap-1">
            {turn.citations.slice(0, 12).map((c) => (
              <Badge
                key={c}
                variant="outline"
                className="font-mono font-normal text-[10px] max-w-full truncate"
              >
                {prettyCitation(c)}
              </Badge>
            ))}
            {turn.citations.length > 12 && (
              <span className="text-[10px] text-muted-foreground">
                +{turn.citations.length - 12} more
              </span>
            )}
          </div>
        </BentoTile>
      )}

      {/* Trace metadata footer tile */}
      {hasResults && (
        <BentoTile
          className="col-span-12"
          icon={<Database />}
          label="Trace"
          accent="muted"
          compact
        >
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
            <span>
              {turn.results!.executionMs}ms SQL execution
            </span>
            <span>·</span>
            <span>{turn.results!.count.toLocaleString()} rows</span>
            {turn.durationMs != null && (
              <>
                <span>·</span>
                <span>{(turn.durationMs / 1000).toFixed(2)}s total</span>
              </>
            )}
            {turn.traceId && (
              <>
                <span>·</span>
                <span className="font-mono">
                  trace {turn.traceId.slice(0, 8)}
                </span>
              </>
            )}
          </div>
        </BentoTile>
      )}
      </div>

      <div className="flex items-center justify-between gap-3 pt-1">
        {turn.followUps.length > 0 && onPickFollowUp ? (
          <div className="flex flex-col gap-2 flex-1 min-w-0">
            <div className="text-[10px] uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
              <Sparkles className="size-3" />
              Explore next
            </div>
            <div className="flex flex-wrap gap-2">
              {turn.followUps.map((q) => (
                <button
                  key={q}
                  onClick={() => onPickFollowUp(q)}
                  className="group rounded-full border bg-card/40 backdrop-blur px-3.5 py-1.5 text-sm text-foreground/80 hover:text-foreground hover:border-primary/40 hover:bg-card transition-all"
                >
                  {q}
                </button>
              ))}
            </div>
          </div>
        ) : (
          <div className="flex-1" />
        )}
        {question && (
          <div className="shrink-0">
            <SaveReportButton turn={turn} questionFromUserTurn={question} />
          </div>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────

type Accent = "primary" | "chart-1" | "chart-2" | "chart-3" | "chart-4" | "chart-5" | "muted";

const ACCENT_HAIRLINE: Record<Accent, string> = {
  primary: "from-transparent via-primary/60 to-transparent",
  "chart-1": "from-transparent via-chart-1/60 to-transparent",
  "chart-2": "from-transparent via-chart-2/60 to-transparent",
  "chart-3": "from-transparent via-chart-3/60 to-transparent",
  "chart-4": "from-transparent via-chart-4/60 to-transparent",
  "chart-5": "from-transparent via-chart-5/60 to-transparent",
  muted: "from-transparent via-border to-transparent",
};

function BentoTile({
  children,
  className,
  icon,
  label,
  accent,
  headerExtra,
  compact = false,
}: {
  children: React.ReactNode;
  className?: string;
  icon: React.ReactNode;
  label: string;
  accent: Accent;
  headerExtra?: React.ReactNode;
  compact?: boolean;
}) {
  return (
    <div
      className={cn(
        "relative rounded-2xl border bg-card/60 backdrop-blur overflow-hidden",
        "transition-colors hover:border-foreground/20",
        className,
      )}
    >
      <div
        className={cn(
          "absolute inset-x-0 top-0 h-px bg-gradient-to-r",
          ACCENT_HAIRLINE[accent],
        )}
      />
      <div
        className={cn(
          "flex items-center gap-1.5 text-[10px] uppercase tracking-wider text-muted-foreground",
          compact ? "px-4 py-2" : "px-4 pt-3",
        )}
      >
        <span className="[&_svg]:size-3">{icon}</span>
        <span>{label}</span>
        {headerExtra && <span className="ml-auto">{headerExtra}</span>}
      </div>
      <div className={cn(compact ? "px-4 pb-2" : "px-4 pb-4 pt-2")}>
        {children}
      </div>
    </div>
  );
}

function KpiHero({ turn }: { turn: AssistantTurn }) {
  const row = turn.results!.rows[0];
  const cols = turn.results!.columns;
  // Pick the numeric column if there is one; otherwise the last column
  const numericCol =
    cols.find((c) => typeof row[c] === "number") ?? cols[cols.length - 1];
  const labelCol = cols.find((c) => c !== numericCol);
  const value = row[numericCol];
  const formattedValue =
    typeof value === "number"
      ? value.toLocaleString()
      : String(value ?? "—");
  const label = labelCol ? String(row[labelCol]) : numericCol;
  return (
    <div className="py-4">
      <div className="text-[11px] uppercase tracking-wider text-muted-foreground">
        {labelCol ? label : prettyName(numericCol)}
      </div>
      <div className="mt-2 text-5xl font-semibold tracking-tight tabular-nums bg-gradient-to-b from-foreground to-foreground/70 bg-clip-text text-transparent">
        {formattedValue}
      </div>
      {labelCol && (
        <div className="mt-1 text-xs text-muted-foreground">
          {prettyName(numericCol)}
        </div>
      )}
    </div>
  );
}

function Stats({ turn }: { turn: AssistantTurn }) {
  const r = turn.results!;
  return (
    <dl className="grid grid-cols-2 gap-3 text-xs">
      <Stat label="Rows" value={r.count.toLocaleString()} />
      <Stat label="SQL exec" value={`${r.executionMs}ms`} />
      <Stat label="Columns" value={String(r.columns.length)} />
      {turn.durationMs != null && (
        <Stat
          label="End-to-end"
          value={`${(turn.durationMs / 1000).toFixed(1)}s`}
        />
      )}
    </dl>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
        {label}
      </div>
      <div className="mt-0.5 text-lg font-semibold tabular-nums">{value}</div>
    </div>
  );
}

function prettyName(name: string): string {
  return name
    .split(/[_\s]+/)
    .map((p) =>
      p.length <= 3
        ? p.toUpperCase()
        : p.charAt(0).toUpperCase() + p.slice(1).toLowerCase(),
    )
    .join(" ");
}

function prettyCitation(id: string): string {
  // Trim the trailing #hash for display
  const hashIdx = id.lastIndexOf("#");
  return hashIdx > 0 ? id.slice(0, hashIdx) : id;
}
