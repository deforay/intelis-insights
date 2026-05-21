/**
 * Admin users — single-resource routes.
 *
 * PATCH  /api/v1/admin/users/[id] — partial update
 * DELETE /api/v1/admin/users/[id] — remove user (cascade to sessions etc.)
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { requireAdmin } from "@/lib/auth/admin";
import { deleteUser, updateUser } from "@/lib/admin/users";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

const AccessLevel = z.enum([
  "district",
  "multi_district",
  "province",
  "multi_province",
  "national",
]);

const PatchBody = z.object({
  email: z.email().optional(),
  password: z.string().min(8).optional(),
  name: z.string().nullable().optional(),
  role: z.enum(["admin", "user"]).optional(),
  accessLevel: AccessLevel.optional(),
  allowedProvinces: z.array(z.string()).optional(),
  allowedDistricts: z.array(z.string()).optional(),
});

interface RouteParams {
  params: Promise<{ id: string }>;
}

export async function PATCH(req: Request, { params }: RouteParams) {
  const session = await requireAdmin();
  if (!session) {
    return NextResponse.json({ error: "forbidden" }, { status: 403 });
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
  try {
    await updateUser(id, parsed.data);
    return NextResponse.json({ updated: id });
  } catch (err) {
    return NextResponse.json(
      { error: (err as Error).message },
      { status: 400 },
    );
  }
}

export async function DELETE(_req: Request, { params }: RouteParams) {
  const session = await requireAdmin();
  if (!session) {
    return NextResponse.json({ error: "forbidden" }, { status: 403 });
  }
  const { id } = await params;
  if (id === session.user.id) {
    return NextResponse.json(
      { error: "you cannot delete the user you are signed in as" },
      { status: 400 },
    );
  }
  await deleteUser(id);
  return NextResponse.json({ deleted: id });
}
