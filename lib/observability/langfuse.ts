/**
 * LangFuse client singleton.
 *
 * Returns null when the LANGFUSE_* env vars aren't all set, so the
 * graph runner's instrumentation calls become no-ops in deployments
 * that don't use it. When set, the SDK batches events in-process and
 * flushes asynchronously — call `flushTraces()` from the route after
 * the response is sent to ensure data lands before short-lived
 * containers exit.
 */
import { Langfuse } from "langfuse";
import { env } from "@/lib/config/env";

declare global {
  var __langfuseClient: Langfuse | null | undefined;
}

function buildClient(): Langfuse | null {
  if (
    !env.LANGFUSE_PUBLIC_KEY ||
    !env.LANGFUSE_SECRET_KEY ||
    !env.LANGFUSE_HOST
  ) {
    return null;
  }
  return new Langfuse({
    publicKey: env.LANGFUSE_PUBLIC_KEY,
    secretKey: env.LANGFUSE_SECRET_KEY,
    baseUrl: env.LANGFUSE_HOST,
  });
}

export function getLangfuse(): Langfuse | null {
  if (globalThis.__langfuseClient === undefined) {
    globalThis.__langfuseClient = buildClient();
  }
  return globalThis.__langfuseClient;
}

export async function flushTraces(): Promise<void> {
  const client = getLangfuse();
  if (!client) return;
  await client.flushAsync();
}
