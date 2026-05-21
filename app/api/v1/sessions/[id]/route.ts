/**
 * GET    /api/v1/sessions/[id] — return a session with its messages.
 * DELETE /api/v1/sessions/[id] — remove the session and its messages.
 *
 * Both require the session to be owned by the authenticated user.
 */
import { NextResponse } from "next/server";
import { and, eq } from "drizzle-orm";
import { auth } from "@/auth";
import { db } from "@/lib/db/app";
import { chatSessions } from "@/lib/db/schema";
import { getSessionForUser, listMessages } from "@/lib/chat/sessions";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

interface RouteParams {
  params: Promise<{ id: string }>;
}

export async function GET(_req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const { id } = await params;
  const row = await getSessionForUser({ sessionId: id, userId: session.user.id });
  if (!row) {
    return NextResponse.json({ error: "not found" }, { status: 404 });
  }
  const messages = await listMessages(id);
  return NextResponse.json({ session: row, messages });
}

export async function DELETE(_req: Request, { params }: RouteParams) {
  const session = await auth();
  if (!session?.user) {
    return NextResponse.json({ error: "unauthorized" }, { status: 401 });
  }

  const { id } = await params;
  const result = await db
    .delete(chatSessions)
    .where(
      and(eq(chatSessions.id, id), eq(chatSessions.userId, session.user.id)),
    )
    .returning({ id: chatSessions.id });

  if (result.length === 0) {
    return NextResponse.json({ error: "not found" }, { status: 404 });
  }
  return NextResponse.json({ deleted: id });
}
