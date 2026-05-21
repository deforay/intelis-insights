/**
 * POST /api/v1/reports/[id]/refresh — re-execute a saved report's SQL.
 *
 * Doesn't re-ask the LLM. Validates access (which may have changed
 * since save) + SQL safety, then runs against the lab DB. Updates
 * `last_run_at` and `last_summary` on success.
 */
import { NextResponse } from "next/server";
import { auth } from "@/auth";
import { userContextFromSession } from "@/lib/auth/rbac";
import { refreshReport } from "@/lib/reports/refresh";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

interface RouteParams {
  params: Promise<{ id: string }>;
}

export async function POST(_req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const { id } = await params;
  const outcome = await refreshReport({
    reportId: id,
    userContext: userContextFromSession(session),
  });
  if (!outcome.ok) {
    return NextResponse.json(
      { error: outcome.reason ?? "refresh failed" },
      { status: 400 },
    );
  }
  return NextResponse.json({ result: outcome.result });
}
