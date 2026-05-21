/**
 * Admin user-management helpers.
 *
 * Wrap the Drizzle queries the admin API and admin UI both need. Bcrypt
 * hashing is centralised here so route handlers and the seed script use
 * the same rounds setting.
 */
import { hash } from "bcryptjs";
import { eq } from "drizzle-orm";
import { db } from "@/lib/db/app";
import { users } from "@/lib/db/schema";
import type { AccessLevel } from "@/lib/auth/rbac";

const BCRYPT_ROUNDS = 12;

export interface UserInput {
  email: string;
  password?: string;
  name?: string | null;
  role: "admin" | "user";
  accessLevel: AccessLevel;
  allowedProvinces: string[];
  allowedDistricts: string[];
}

export async function listUsers() {
  return db
    .select({
      id: users.id,
      email: users.email,
      name: users.name,
      role: users.role,
      accessLevel: users.accessLevel,
      allowedProvinces: users.allowedProvinces,
      allowedDistricts: users.allowedDistricts,
      createdAt: users.createdAt,
      updatedAt: users.updatedAt,
    })
    .from(users)
    .orderBy(users.createdAt);
}

export async function createUser(input: UserInput): Promise<string> {
  if (!input.password || input.password.length < 8) {
    throw new Error("password must be at least 8 characters");
  }
  const passwordHash = await hash(input.password, BCRYPT_ROUNDS);
  const [row] = await db
    .insert(users)
    .values({
      email: input.email,
      passwordHash,
      name: input.name ?? null,
      role: input.role,
      accessLevel: input.accessLevel,
      allowedProvinces: input.allowedProvinces,
      allowedDistricts: input.allowedDistricts,
    })
    .returning({ id: users.id });
  return row.id;
}

export interface UserUpdate {
  email?: string;
  password?: string;
  name?: string | null;
  role?: "admin" | "user";
  accessLevel?: AccessLevel;
  allowedProvinces?: string[];
  allowedDistricts?: string[];
}

export async function updateUser(
  id: string,
  patch: UserUpdate,
): Promise<void> {
  const set: Record<string, unknown> = {};
  if (patch.email !== undefined) set.email = patch.email;
  if (patch.name !== undefined) set.name = patch.name;
  if (patch.role !== undefined) set.role = patch.role;
  if (patch.accessLevel !== undefined) set.accessLevel = patch.accessLevel;
  if (patch.allowedProvinces !== undefined)
    set.allowedProvinces = patch.allowedProvinces;
  if (patch.allowedDistricts !== undefined)
    set.allowedDistricts = patch.allowedDistricts;
  if (patch.password) {
    if (patch.password.length < 8) {
      throw new Error("password must be at least 8 characters");
    }
    set.passwordHash = await hash(patch.password, BCRYPT_ROUNDS);
  }
  if (Object.keys(set).length === 0) return;
  set.updatedAt = new Date();
  await db.update(users).set(set).where(eq(users.id, id));
}

export async function deleteUser(id: string): Promise<void> {
  await db.delete(users).where(eq(users.id, id));
}
