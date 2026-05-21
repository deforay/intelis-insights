#!/usr/bin/env tsx
/**
 * Seed a single superadmin user.
 *
 * Idempotent: if a user with the given email already exists, the
 * password is rotated to the supplied value. Useful to recover access
 * without touching the DB by hand.
 *
 * Usage:
 *   SEED_ADMIN_EMAIL=you@example.org \
 *   SEED_ADMIN_PASSWORD=somethingstrong \
 *   npm run seed:admin
 *
 * The seeded user has:
 *   - role: admin
 *   - accessLevel: national  (sees all data; no row-level scope injection)
 */
import "dotenv/config";
import { hash } from "bcryptjs";
import { eq } from "drizzle-orm";
import { db } from "@/lib/db/app";
import { users } from "@/lib/db/schema";

const BCRYPT_ROUNDS = 12;

async function main() {
  const email = process.env.SEED_ADMIN_EMAIL?.trim();
  const password = process.env.SEED_ADMIN_PASSWORD;
  const name = process.env.SEED_ADMIN_NAME?.trim() ?? null;

  if (!email || !password) {
    console.error(
      "Set SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD before running.\n" +
        "  e.g. SEED_ADMIN_EMAIL=you@example.org SEED_ADMIN_PASSWORD=changeme npm run seed:admin",
    );
    process.exit(1);
  }
  if (password.length < 8) {
    console.error("SEED_ADMIN_PASSWORD must be at least 8 characters.");
    process.exit(1);
  }

  const passwordHash = await hash(password, BCRYPT_ROUNDS);

  const [existing] = await db
    .select({ id: users.id })
    .from(users)
    .where(eq(users.email, email))
    .limit(1);

  if (existing) {
    await db
      .update(users)
      .set({
        passwordHash,
        role: "admin",
        accessLevel: "national",
        name,
        updatedAt: new Date(),
      })
      .where(eq(users.id, existing.id));
    console.log(`Updated existing user ${email} (role=admin, scope=national).`);
  } else {
    const [row] = await db
      .insert(users)
      .values({
        email,
        passwordHash,
        role: "admin",
        accessLevel: "national",
        name,
      })
      .returning({ id: users.id });
    console.log(`Created admin user ${email} (id=${row.id}).`);
  }

  console.log("Done. Sign in at /login.");
  process.exit(0);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
