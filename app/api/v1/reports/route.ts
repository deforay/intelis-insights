/**
 * GET  /api/v1/reports        — list current user's saved reports
 * POST /api/v1/reports        — save a new report from an answer
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { auth } from "@/auth";
import { createReport, listReports } from "@/lib/reports/store";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const ChartSuggestionShape = z
  .object({
    recommended: z.string(),
    alternatives: z.array(z.string()).default([]),
    config: z
      .object({
        xAxis: z.string(),
        yAxis: z.string(),
        series: z.string().nullable(),
        title: z.string(),
      })
      .optional(),
    reasoning: z.string().optional(),
  })
  .passthrough();

const CreateBody = z.object({
  title: z.string().trim().min(1).max(120),
  question: z.string().trim().min(1),
  sql: z.string().nullable(),
  chartConfig: ChartSuggestionShape.nullable().optional(),
  pinned: z.boolean().optional(),
  lastSummary: z
    .object({
      rowCount: z.number().int().nonnegative(),
      executionMs: z.number().int().nonnegative(),
      scalarValue: z.number().nullable().optional(),
      firstColumn: z.string().optional(),
    })
    .nullable()
    .optional(),
});

export async function GET(req: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const url = new URL(req.url);
  const pinnedOnly = url.searchParams.get("pinned") === "1";
  const rows = await listReports({
    userId: session.user.id,
    pinnedOnly,
  });
  return NextResponse.json({ reports: rows });
}

export async function POST(req: Request) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const raw = await req.json().catch(() => null);
  const parsed = CreateBody.safeParse(raw);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "invalid request", details: parsed.error.issues },
      { status: 400 },
    );
  }
  const id = await createReport({
    userId: session.user.id,
    title: parsed.data.title,
    question: parsed.data.question,
    sql: parsed.data.sql,
    chartConfig: (parsed.data.chartConfig ??
      null) as unknown as Parameters<typeof createReport>[0]["chartConfig"],
    lastSummary: parsed.data.lastSummary ?? null,
    pinned: parsed.data.pinned ?? false,
  });
  return NextResponse.json({ id }, { status: 201 });
}
