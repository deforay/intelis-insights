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
import { getLanguageModel, type ModelKind } from "./providers";

export interface StructuredOptions<T extends z.ZodTypeAny> {
  schema: T;
  schemaName?: string;
  schemaDescription?: string;
  system: string;
  prompt: string;
  modelKind?: ModelKind;
  model?: LanguageModel;
  temperature?: number;
  maxOutputTokens?: number;
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
  const result = await generateObject({
    model,
    schema: opts.schema,
    schemaName: opts.schemaName,
    schemaDescription: opts.schemaDescription,
    system: opts.system,
    prompt: opts.prompt,
    temperature: opts.temperature ?? 0,
    maxOutputTokens: opts.maxOutputTokens,
  });
  return {
    object: result.object as z.infer<T>,
    usage: result.usage,
    modelId: typeof model === "string" ? model : model.modelId,
  };
}
