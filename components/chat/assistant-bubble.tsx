"use client";

import { useState } from "react";
import { ShieldCheck, BarChart3, Table2, Code2, Quote } from "lucide-react";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { ProgressSteps } from "./progress-steps";
import { AssumptionsBanner } from "./assumptions-banner";
import { SqlBlock } from "./sql-block";
import { ErrorCard } from "./error-card";
import { ResultTable } from "./result-table";
import { ChartRenderer } from "@/components/chart/chart-renderer";
import type { AssistantTurn } from "./types";

export function AssistantBubble({ turn }: { turn: AssistantTurn }) {
  const [tab, setTab] = useState("chart");
  const hasResults = !!turn.results;
  const hasChart = !!turn.chart && turn.chart.recommended !== "table";

  return (
    <div className="flex flex-col gap-3">
      {(turn.isStreaming || Object.keys(turn.stages).length > 0) &&
        !turn.results &&
        !turn.error && (
          <ProgressSteps stages={turn.stages} isStreaming={turn.isStreaming} />
        )}

      {turn.assumptions.length > 0 && (
        <AssumptionsBanner assumptions={turn.assumptions} />
      )}

      {turn.accessDecision?.allowed &&
        turn.accessDecision.reason.startsWith("injected") && (
          <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <ShieldCheck className="size-3.5" />
            {turn.accessDecision.reason}
          </div>
        )}

      {turn.sql && (
        <SqlBlock
          sql={turn.sql}
          confidence={turn.sqlConfidence}
          rewritten={!!turn.accessDecision?.rewrittenSql && turn.accessDecision.reason.startsWith("injected")}
        />
      )}

      {turn.error && (
        <ErrorCard
          code={turn.error.code}
          message={turn.error.message}
          stage={turn.error.stage}
        />
      )}

      {turn.isStreaming && !hasResults && !turn.error && (
        <Skeleton className="h-[180px] w-full rounded-lg" />
      )}

      {hasResults && turn.results && (
        <div className="rounded-xl border bg-card overflow-hidden">
          <Tabs value={tab} onValueChange={setTab} className="w-full">
            <div className="flex items-center justify-between border-b px-3 py-2">
              <TabsList className="bg-transparent gap-1 p-0">
                {hasChart && (
                  <TabsTrigger value="chart" className="gap-1.5">
                    <BarChart3 className="size-3.5" />
                    Chart
                  </TabsTrigger>
                )}
                <TabsTrigger value="table" className="gap-1.5">
                  <Table2 className="size-3.5" />
                  Table
                </TabsTrigger>
                {turn.sql && (
                  <TabsTrigger value="sql" className="gap-1.5">
                    <Code2 className="size-3.5" />
                    SQL
                  </TabsTrigger>
                )}
                {turn.citations.length > 0 && (
                  <TabsTrigger value="citations" className="gap-1.5">
                    <Quote className="size-3.5" />
                    Citations
                  </TabsTrigger>
                )}
              </TabsList>
              <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                {turn.results.executionMs != null && (
                  <Badge variant="secondary" className="font-normal">
                    {turn.results.executionMs}ms · {turn.results.count} rows
                  </Badge>
                )}
              </div>
            </div>
            {hasChart && turn.chart && (
              <TabsContent value="chart" className="p-4 mt-0">
                <ChartRenderer chart={turn.chart} result={turn.results} />
                {turn.chart.reasoning && (
                  <p className="mt-2 text-[11px] text-muted-foreground">
                    {turn.chart.reasoning}
                  </p>
                )}
              </TabsContent>
            )}
            <TabsContent value="table" className="p-3 mt-0">
              <ResultTable result={turn.results} />
            </TabsContent>
            {turn.sql && (
              <TabsContent value="sql" className="p-3 mt-0">
                <pre className="overflow-x-auto p-3 text-[11px] leading-relaxed font-mono text-foreground/90 rounded-lg bg-muted/30 border">
                  {turn.accessDecision?.rewrittenSql ?? turn.sql}
                </pre>
              </TabsContent>
            )}
            {turn.citations.length > 0 && (
              <TabsContent value="citations" className="p-3 mt-0">
                <div className="flex flex-wrap gap-1.5">
                  {turn.citations.map((c) => (
                    <Badge key={c} variant="outline" className="font-mono font-normal">
                      {c}
                    </Badge>
                  ))}
                </div>
              </TabsContent>
            )}
          </Tabs>
        </div>
      )}

      {turn.durationMs != null && !turn.isStreaming && (
        <div className="text-[10px] text-muted-foreground/70">
          {(turn.durationMs / 1000).toFixed(2)}s · trace {turn.traceId?.slice(0, 8)}
        </div>
      )}
    </div>
  );
}
