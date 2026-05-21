/**
 * GET /api/v1/admin/audit — list audit-log rows (admin only).
 *
 * Query params:
 *   ?limit=N    — max rows (default 100, max 500)
 *   ?userId=X   — filter by a specific user
 */
import { NextResponse } from "next/server";
import { requireAdmin } from "@/lib/auth/admin";
import { listAuditLog } from "@/lib/admin/audit";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

export async function GET(req: Request) {
  const session = await requireAdmin();
  if (!session) {
    return NextResponse.json({ error: "forbidden" }, { status: 403 });
  }
  const url = new URL(req.url);
  const limitRaw = Number(url.searchParams.get("limit") ?? "100");
  const limit = Math.min(500, Math.max(1, Number.isFinite(limitRaw) ? limitRaw : 100));
  const userId = url.searchParams.get("userId") ?? undefined;
  const rows = await listAuditLog({ limit, userId });
  return NextResponse.json({ rows });
}
