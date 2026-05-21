#!/usr/bin/env tsx
/**
 * Embed and upsert the snippet corpus into Qdrant.
 *
 * Reads `corpus/snippets.jsonl` (produced by `npm run rag:build`), embeds
 * each snippet's `text` field via the configured embeddings provider, and
 * upserts the resulting points into the Qdrant collection.
 *
 * Flags:
 *   --reset       drop the collection before upserting (irreversible)
 *   --batch=N     batch size for embed + upsert (default 64)
 *
 * Usage:
 *   npm run rag:upsert
 *   npm run rag:upsert -- --reset
 */
import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import readline from "node:readline";
import { env } from "@/lib/config/env";
import { embedBatch, getEmbeddingDimension } from "@/lib/rag/embeddings";
import {
  ensureCollection,
  resetCollection,
  upsertSnippets,
} from "@/lib/rag/qdrant";
import type { Snippet } from "@/lib/rag/snippets";

const INPUT = path.resolve("corpus/snippets.jsonl");

interface CliFlags {
  reset: boolean;
  batchSize: number;
}

function parseFlags(argv: string[]): CliFlags {
  let reset = false;
  let batchSize = 64;
  for (const a of argv) {
    if (a === "--reset") reset = true;
    else if (a.startsWith("--batch=")) {
      const n = Number(a.slice("--batch=".length));
      if (Number.isFinite(n) && n > 0) batchSize = n;
    }
  }
  return { reset, batchSize };
}

async function readSnippets(filePath: string): Promise<Snippet[]> {
  const rl = readline.createInterface({
    input: fs.createReadStream(filePath, "utf-8"),
    crlfDelay: Infinity,
  });
  const out: Snippet[] = [];
  for await (const line of rl) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    out.push(JSON.parse(trimmed) as Snippet);
  }
  return out;
}

async function main() {
  if (!fs.existsSync(INPUT)) {
    throw new Error(
      `corpus/snippets.jsonl not found. Run \`npm run rag:build\` first.`,
    );
  }

  const flags = parseFlags(process.argv.slice(2));
  const snippets = await readSnippets(INPUT);
  console.log(`Loaded ${snippets.length} snippets from ${INPUT}`);

  const dim = await getEmbeddingDimension();
  console.log(
    `Embeddings: provider=${env.EMBEDDINGS_PROVIDER} model=${env.EMBEDDINGS_MODEL} dim=${dim}`,
  );

  if (flags.reset) {
    console.log(`Dropping collection ${env.QDRANT_COLLECTION} (--reset)`);
    await resetCollection(env.QDRANT_COLLECTION);
  }

  await ensureCollection(env.QDRANT_COLLECTION, dim);

  let done = 0;
  const start = Date.now();
  for (let i = 0; i < snippets.length; i += flags.batchSize) {
    const batch = snippets.slice(i, i + flags.batchSize);
    const texts = batch.map((s) => s.text);
    const vectors = await embedBatch(texts);
    if (vectors.length !== batch.length) {
      throw new Error(
        `embedBatch returned ${vectors.length} vectors for ${batch.length} inputs`,
      );
    }
    await upsertSnippets(
      env.QDRANT_COLLECTION,
      batch.map((snippet, j) => ({ snippet, vector: vectors[j] })),
    );
    done += batch.length;
    const elapsed = ((Date.now() - start) / 1000).toFixed(1);
    console.log(`  ${done}/${snippets.length} upserted (${elapsed}s)`);
  }

  console.log(
    `Done. ${snippets.length} snippets in collection "${env.QDRANT_COLLECTION}" in ${((Date.now() - start) / 1000).toFixed(1)}s`,
  );
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
