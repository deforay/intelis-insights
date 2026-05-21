#!/usr/bin/env tsx
/**
 * One-shot bootstrap for the Docker `init` service.
 *
 * Runs to completion before the `app` service starts. Each step is
 * idempotent — re-running on an already-initialised stack is a no-op
 * (or a small upsert if the corpus changed).
 *
 * Steps:
 *   1. Wait for Postgres + Qdrant to be reachable.
 *   2. Apply the app schema (drizzle-kit push).
 *   3. Export the InteLIS MySQL schema → corpus/schema.json
 *        (skipped if the file already exists).
 *   4. Build RAG snippets → corpus/snippets.jsonl
 *        (rebuilt if snippets.jsonl is missing or older than schema.json).
 *   5. Upsert snippets into Qdrant
 *        (skipped if the collection exists with a non-zero point count
 *         and the snippets file hasn't changed).
 *   6. Seed admin user
 *        (if SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD are set; safe to
 *         re-run — rotates the password if the user already exists).
 *
 * To force a full re-init, `docker volume rm intelis-insights_corpus_data`
 * and `docker compose down -v postgres qdrant`.
 */
import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";
import postgres from "postgres";

const READY_TIMEOUT_MS = 90_000;
const READY_POLL_MS = 1500;

const CORPUS_DIR = path.resolve("corpus");
const SCHEMA_PATH = path.join(CORPUS_DIR, "schema.json");
const SNIPPETS_PATH = path.join(CORPUS_DIR, "snippets.jsonl");

async function main() {
  console.log("── init: InteLIS Insights ─────────────────────────");

  await waitForPostgres();
  await waitForQdrant();

  await applySchema();
  await ensureCorpus();
  await ensureQdrantPopulated();
  await maybeSeedAdmin();

  console.log("\n✓ init complete — app may start.");
}

// ── Waits ────────────────────────────────────────────────────────────

async function waitForPostgres(): Promise<void> {
  const url = process.env.APP_DB_URL;
  if (!url) throw new Error("APP_DB_URL must be set");
  process.stdout.write("Waiting for Postgres… ");
  const deadline = Date.now() + READY_TIMEOUT_MS;
  while (Date.now() < deadline) {
    const sql = postgres(url, { max: 1, idle_timeout: 1, connect_timeout: 2 });
    try {
      await sql`SELECT 1`;
      await sql.end({ timeout: 1 });
      console.log("ready.");
      return;
    } catch {
      await sql.end({ timeout: 1 }).catch(() => {});
      await sleep(READY_POLL_MS);
    }
  }
  throw new Error(`Postgres not reachable within ${READY_TIMEOUT_MS / 1000}s`);
}

async function waitForQdrant(): Promise<void> {
  const url = process.env.QDRANT_URL;
  if (!url) throw new Error("QDRANT_URL must be set");
  process.stdout.write("Waiting for Qdrant…   ");
  const deadline = Date.now() + READY_TIMEOUT_MS;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${url.replace(/\/$/, "")}/readyz`);
      if (res.ok) {
        console.log("ready.");
        return;
      }
    } catch {
      /* retry */
    }
    await sleep(READY_POLL_MS);
  }
  throw new Error(`Qdrant not reachable within ${READY_TIMEOUT_MS / 1000}s`);
}

// ── Steps ────────────────────────────────────────────────────────────

async function applySchema(): Promise<void> {
  console.log("\n[1/4] Applying app schema (drizzle-kit push)…");
  await runCmd("npx", ["drizzle-kit", "push", "--force"]);
}

async function ensureCorpus(): Promise<void> {
  if (!fs.existsSync(CORPUS_DIR)) fs.mkdirSync(CORPUS_DIR, { recursive: true });

  if (!fs.existsSync(SCHEMA_PATH)) {
    console.log("\n[2/4] Exporting InteLIS MySQL schema…");
    await runCmd("npx", ["tsx", "scripts/export-schema.ts"]);
  } else {
    console.log("\n[2/4] corpus/schema.json present — skipping export.");
  }

  const schemaMtime = fs.statSync(SCHEMA_PATH).mtimeMs;
  const snippetsStale =
    !fs.existsSync(SNIPPETS_PATH) ||
    fs.statSync(SNIPPETS_PATH).mtimeMs < schemaMtime;

  if (snippetsStale) {
    console.log("\n[3/4] Building RAG snippets…");
    await runCmd("npx", ["tsx", "scripts/build-snippets.ts"]);
  } else {
    console.log("\n[3/4] corpus/snippets.jsonl up to date — skipping build.");
  }
}

async function ensureQdrantPopulated(): Promise<void> {
  const url = process.env.QDRANT_URL!;
  const collection = process.env.QDRANT_COLLECTION ?? "intelis_insights";
  const apiKey = process.env.QDRANT_API_KEY;

  let pointsCount: number | null = null;
  try {
    const res = await fetch(
      `${url.replace(/\/$/, "")}/collections/${encodeURIComponent(collection)}`,
      { headers: apiKey ? { "api-key": apiKey } : {} },
    );
    if (res.ok) {
      const body = (await res.json()) as {
        result?: { points_count?: number };
      };
      pointsCount = body.result?.points_count ?? 0;
    }
  } catch {
    /* collection missing — treat as 0 */
  }

  if (pointsCount && pointsCount > 0) {
    console.log(
      `\n[4/4] Qdrant collection "${collection}" already populated (${pointsCount} points) — skipping upsert.`,
    );
    return;
  }

  console.log("\n[4/4] Upserting snippets into Qdrant…");
  await runCmd("npx", ["tsx", "scripts/upsert-corpus.ts"]);
}

async function maybeSeedAdmin(): Promise<void> {
  const email = process.env.SEED_ADMIN_EMAIL?.trim();
  const password = process.env.SEED_ADMIN_PASSWORD;
  if (!email || !password) {
    console.log(
      "\nSEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD not set — skipping admin seed.",
    );
    return;
  }
  console.log(`\nSeeding admin user (${email})…`);
  await runCmd("npx", ["tsx", "scripts/seed-admin.ts"]);
}

// ── Helpers ──────────────────────────────────────────────────────────

function runCmd(cmd: string, args: string[]): Promise<void> {
  return new Promise((resolve, reject) => {
    const proc = spawn(cmd, args, { stdio: "inherit", env: process.env });
    proc.on("error", reject);
    proc.on("close", (code) => {
      if (code === 0) resolve();
      else reject(new Error(`${cmd} ${args.join(" ")} exited with code ${code}`));
    });
  });
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

main().catch((err) => {
  console.error("\n✗ init failed:", err.message);
  process.exit(1);
});
