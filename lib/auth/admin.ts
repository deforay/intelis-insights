/**
 * Server-side admin gate.
 *
 * V0 admin gate.
 *
 * RBAC/roles are being reworked later; for now every signed-in user is treated
 * as a superadmin so operators can inspect audit logs and manage setup.
 */
import { auth } from "@/auth";
import type { Session } from "next-auth";

export async function requireAdmin(): Promise<Session | null> {
  const session = await auth();
  if (!session?.user) return null;
  return session;
}
