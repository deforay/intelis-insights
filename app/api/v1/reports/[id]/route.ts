/**
 * GET    /api/v1/reports/[id] — fetch a single saved report
 * PATCH  /api/v1/reports/[id] — rename / pin-unpin
 * DELETE /api/v1/reports/[id] — remove the report
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { auth } from "@/auth";
import {
  deleteReport,
  getReportForUser,
  updateReport,
} from "@/lib/reports/store";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const PatchBody = z.object({
  title: z.string().trim().min(1).max(120).optional(),
  pinned: z.boolean().optional(),
});

interface RouteParams {
  params: Promise<{ id: string }>;
}

export async function GET(_req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const { id } = await params;
  const row = await getReportForUser({
    reportId: id,
    userId: session.user.id,
  });
  if (!row) {
    return NextResponse.json({ error: "not found" }, { status: 404 });
  }
  return NextResponse.json({ report: row });
}

export async function PATCH(req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const { id } = await params;
  const raw = await req.json().catch(() => null);
  const parsed = PatchBody.safeParse(raw);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "invalid request", details: parsed.error.issues },
      { status: 400 },
    );
  }
  const existing = await getReportForUser({
    reportId: id,
    userId: session.user.id,
  });
  if (!existing) {
    return NextResponse.json({ error: "not found" }, { status: 404 });
  }
  await updateReport(id, session.user.id, parsed.data);
  return NextResponse.json({ updated: id });
}

export async function DELETE(_req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const { id } = await params;
  const deleted = await deleteReport(id, session.user.id);
  if (!deleted) {
    return NextResponse.json({ error: "not found" }, { status: 404 });
  }
  return NextResponse.json({ deleted: id });
}
