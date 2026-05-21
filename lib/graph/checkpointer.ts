/**
 * Postgres checkpointer singleton for the LangGraph workflow.
 *
 * `PostgresSaver.setup()` is idempotent — it creates the checkpoint
 * tables if absent and returns immediately otherwise. We run it on
 * first access and cache the saver for the process lifetime.
 *
 * The saver shares the Postgres database with app state (users,
 * sessions, audit log) but writes to its own LangGraph-managed tables.
 */
import { PostgresSaver } from "@langchain/langgraph-checkpoint-postgres";
import { env } from "@/lib/config/env";

let cached: Promise<PostgresSaver> | null = null;

export function getCheckpointer(): Promise<PostgresSaver> {
  if (!cached) cached = init();
  return cached;
}

async function init(): Promise<PostgresSaver> {
  const saver = PostgresSaver.fromConnString(env.APP_DB_URL);
  await saver.setup();
  return saver;
}
