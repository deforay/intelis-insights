/**
 * Admin users collection — list and create.
 *
 * GET  /api/v1/admin/users — list every user (admin only)
 * POST /api/v1/admin/users — create a new user (admin only)
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { requireAdmin } from "@/lib/auth/admin";
import { createUser, listUsers } from "@/lib/admin/users";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const AccessLevel = z.enum([
  "district",
  "multi_district",
  "province",
  "multi_province",
  "national",
]);

const CreateBody = z.object({
  email: z.email(),
  password: z.string().min(8),
  name: z.string().optional().nullable(),
  role: z.enum(["admin", "user"]).default("user"),
  accessLevel: AccessLevel,
  allowedProvinces: z.array(z.string()).default([]),
  allowedDistricts: z.array(z.string()).default([]),
});

export async function GET() {
  const session = await requireAdmin();
  if (!session) {
    return NextResponse.json({ error: "forbidden" }, { status: 403 });
  }
  const rows = await listUsers();
  return NextResponse.json({ users: rows });
}

export async function POST(req: Request) {
  const session = await requireAdmin();
  if (!session) {
    return NextResponse.json({ error: "forbidden" }, { status: 403 });
  }
  const raw = await req.json().catch(() => null);
  const parsed = CreateBody.safeParse(raw);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "invalid request", details: parsed.error.issues },
      { status: 400 },
    );
  }
  try {
    const id = await createUser(parsed.data);
    return NextResponse.json({ id }, { status: 201 });
  } catch (err) {
    return NextResponse.json(
      { error: (err as Error).message },
      { status: 400 },
    );
  }
}
