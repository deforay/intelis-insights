/**
 * GET /api/v1/sessions — list the current user's sessions, newest first.
 */
import { NextResponse } from "next/server";
import { auth } from "@/auth";
import { listSessions } from "@/lib/chat/sessions";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

export async function GET() {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }
  const rows = await listSessions(session.user.id);
  return NextResponse.json({ sessions: rows });
}
