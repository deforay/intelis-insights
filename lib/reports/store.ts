/**
 * Reports — saved questions a user can re-run on demand.
 *
 * A report is a (title, NL question, last-generated SQL, chart config)
 * tuple owned by a user. Pinned reports show on the dashboard;
 * unpinned ones live in the reports library.
 */
import { and, desc, eq, sql as drizzleSql } from "drizzle-orm";
import { db } from "@/lib/db/app";
import { reports } from "@/lib/db/schema";
import type { ChartSuggestion, LabQueryResult } from "@/lib/graph/types";

export interface ReportSummary {
  rowCount: number;
  executionMs: number;
  /** First numeric value of a single-row result — for KPI card preview. */
  scalarValue?: number | null;
  /** First column header — useful for KPI label. */
  firstColumn?: string;
}

export interface ReportRow {
  id: string;
  userId: string;
  title: string;
  question: string;
  sql: string | null;
  chartConfig: ChartSuggestion | null;
  lastSummary: ReportSummary | null;
  pinned: boolean;
  lastRunAt: Date | null;
  createdAt: Date;
  updatedAt: Date;
}

function rowToReport(row: typeof reports.$inferSelect): ReportRow {
  return {
    id: row.id,
    userId: row.userId,
    title: row.title,
    question: row.question,
    sql: row.sql ?? null,
    chartConfig: (row.chartConfig as ChartSuggestion | null) ?? null,
    lastSummary: (row.lastSummary as ReportSummary | null) ?? null,
    pinned: row.pinned === 1,
    lastRunAt: row.lastRunAt,
    createdAt: row.createdAt,
    updatedAt: row.updatedAt,
  };
}

export interface SaveReportInput {
  userId: string;
  title: string;
  question: string;
  sql: string | null;
  chartConfig: ChartSuggestion | null;
  lastSummary: ReportSummary | null;
  pinned?: boolean;
}

export async function createReport(input: SaveReportInput): Promise<string> {
  const [row] = await db
    .insert(reports)
    .values({
      userId: input.userId,
      title: input.title,
      question: input.question,
      sql: input.sql,
      chartConfig: input.chartConfig,
      lastSummary: input.lastSummary,
      pinned: input.pinned ? 1 : 0,
      lastRunAt: new Date(),
    })
    .returning({ id: reports.id });
  return row.id;
}

export async function listReports(args: {
  userId: string;
  pinnedOnly?: boolean;
}): Promise<ReportRow[]> {
  const conditions = [eq(reports.userId, args.userId)];
  if (args.pinnedOnly) conditions.push(eq(reports.pinned, 1));
  const rows = await db
    .select()
    .from(reports)
    .where(and(...conditions))
    .orderBy(desc(reports.pinned), desc(reports.lastRunAt));
  return rows.map(rowToReport);
}

export async function getReportForUser(args: {
  reportId: string;
  userId: string;
}): Promise<ReportRow | null> {
  const [row] = await db
    .select()
    .from(reports)
    .where(
      and(eq(reports.id, args.reportId), eq(reports.userId, args.userId)),
    )
    .limit(1);
  return row ? rowToReport(row) : null;
}

export interface UpdateReportInput {
  title?: string;
  pinned?: boolean;
  sql?: string | null;
  chartConfig?: ChartSuggestion | null;
  lastSummary?: ReportSummary | null;
  lastRunAt?: Date;
}

export async function updateReport(
  reportId: string,
  userId: string,
  patch: UpdateReportInput,
): Promise<void> {
  const set: Record<string, unknown> = { updatedAt: new Date() };
  if (patch.title !== undefined) set.title = patch.title;
  if (patch.pinned !== undefined) set.pinned = patch.pinned ? 1 : 0;
  if (patch.sql !== undefined) set.sql = patch.sql;
  if (patch.chartConfig !== undefined) set.chartConfig = patch.chartConfig;
  if (patch.lastSummary !== undefined) set.lastSummary = patch.lastSummary;
  if (patch.lastRunAt !== undefined) set.lastRunAt = patch.lastRunAt;
  await db
    .update(reports)
    .set(set)
    .where(and(eq(reports.id, reportId), eq(reports.userId, userId)));
}

export async function deleteReport(
  reportId: string,
  userId: string,
): Promise<boolean> {
  const result = await db
    .delete(reports)
    .where(and(eq(reports.id, reportId), eq(reports.userId, userId)))
    .returning({ id: reports.id });
  return result.length > 0;
}

/**
 * Build a ReportSummary from a fresh execution result. Tries to extract
 * a scalar number for single-cell results (the KPI tile case).
 */
export function summariseResult(result: LabQueryResult): ReportSummary {
  const summary: ReportSummary = {
    rowCount: result.count,
    executionMs: result.executionMs,
    firstColumn: result.columns[0],
  };
  if (result.count === 1 && result.columns.length <= 2) {
    const row = result.rows[0];
    for (const col of result.columns) {
      const v = row[col];
      if (typeof v === "number") {
        summary.scalarValue = v;
        break;
      }
      if (typeof v === "string") {
        const n = Number(v.replace(/,/g, ""));
        if (Number.isFinite(n) && /\d/.test(v)) {
          summary.scalarValue = n;
          break;
        }
      }
    }
  }
  return summary;
}
// Drizzle reference kept so the linter doesn't complain about the import
// in some environments where the helper is unused — we use `and` + `eq`
// above but `sql` from drizzle-orm is exported for callers that need to
// extend update expressions.
void drizzleSql;
