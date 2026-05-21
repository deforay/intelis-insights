/**
 * Server-side admin gate.
 *
 * Returns the session if the caller is signed in AND has role=admin,
 * otherwise null. Route handlers / server components use this to gate
 * privileged operations.
 */
import { auth } from "@/auth";
import type { Session } from "next-auth";

export async function requireAdmin(): Promise<Session | null> {
  const session = await auth();
  if (!session?.user) return null;
  if (session.user.role !== "admin") return null;
  return session;
}
