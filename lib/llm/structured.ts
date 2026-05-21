/**
 * Thin wrapper around the Vercel AI SDK's `generateObject` for the graph.
 *
 * Centralises model selection, default temperature (0 for SQL/intent), and
 * a small ergonomic shim so callers can pick "primary" or "fast" instead
 * of plumbing a `LanguageModel` instance themselves. Also returns the
 * provider-reported token usage so the audit log can track per-query cost.
 */
import { generateObject, type LanguageModelUsage } from "ai";
import type { LanguageModel } from "ai";
import type { z } from "zod";
import {
  getLanguageModel,
  getStructuredMode,
  type ModelKind,
  type StructuredMode,
} from "./providers";

export interface StructuredOptions<T extends z.ZodTypeAny> {
  schema: T;
  schemaName?: string;
  schemaDescription?: string;
  system: string;
  prompt: string;
  modelKind?: ModelKind;
  model?: LanguageModel;
  /**
   * Override the per-provider default structured mode. Useful for forcing
   * "tool" mode on models that don't reliably honour `json_schema`.
   */
  mode?: StructuredMode;
  temperature?: number;
  maxOutputTokens?: number;
  /**
   * How many times the AI SDK should retry transient failures
   * (rate-limit / network / 5xx). The SDK only retries when the
   * error is marked retryable, so a 4xx like 401 or 400 still
   * fails fast. Default 3 (one beyond AI SDK's default of 2).
   */
  maxRetries?: number;
}

export interface StructuredResult<T> {
  object: T;
  usage: LanguageModelUsage;
  modelId: string;
}

export async function generateStructured<T extends z.ZodTypeAny>(
  opts: StructuredOptions<T>,
): Promise<StructuredResult<z.infer<T>>> {
  const model = opts.model ?? getLanguageModel(opts.modelKind ?? "primary");
  const mode = opts.mode ?? getStructuredMode();
  const result = await generateObject({
    model,
    schema: opts.schema,
    schemaName: opts.schemaName,
    schemaDescription: opts.schemaDescription,
    mode,
    system: opts.system,
    prompt: opts.prompt,
    temperature: opts.temperature ?? 0,
    maxOutputTokens: opts.maxOutputTokens,
    maxRetries: opts.maxRetries ?? 3,
  });
  return {
    object: result.object as z.infer<T>,
    usage: result.usage,
    modelId: typeof model === "string" ? model : model.modelId,
  };
}
