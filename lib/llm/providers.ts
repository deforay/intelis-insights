/**
 * Language-model provider factory.
 *
 * Picks a provider once at startup based on `LLM_PROVIDER` and returns a
 * Vercel AI SDK `LanguageModel` for `getLanguageModel(kind)`. Two model
 * slots are supported:
 *   - "primary" — the SQL generator (`LLM_MODEL`)
 *   - "fast"    — a smaller/cheaper model used for secondary tasks like
 *                 the chart-suggestion fallback (`LLM_MODEL_FAST`)
 *
 * Provider credentials are validated by `lib/config/env.ts` at boot, so
 * the non-null assertions below are safe — env-load throws if the key is
 * missing for the selected provider.
 */
import { createOpenAI } from "@ai-sdk/openai";
import { createAnthropic } from "@ai-sdk/anthropic";
import { createGoogleGenerativeAI } from "@ai-sdk/google";
import { createMistral } from "@ai-sdk/mistral";
import type { LanguageModel } from "ai";
import { env } from "@/lib/config/env";

export type ModelKind = "primary" | "fast";

const cache: Partial<Record<ModelKind, LanguageModel>> = {};

export function getLanguageModel(kind: ModelKind = "primary"): LanguageModel {
  const cached = cache[kind];
  if (cached) return cached;
  const modelId = kind === "fast" ? env.LLM_MODEL_FAST : env.LLM_MODEL;
  const model = buildModel(modelId);
  cache[kind] = model;
  return model;
}

function buildModel(modelId: string): LanguageModel {
  switch (env.LLM_PROVIDER) {
    case "openai": {
      const provider = createOpenAI({ apiKey: env.OPENAI_API_KEY! });
      return provider(modelId);
    }
    case "anthropic": {
      const provider = createAnthropic({ apiKey: env.ANTHROPIC_API_KEY! });
      return provider(modelId);
    }
    case "google": {
      const provider = createGoogleGenerativeAI({
        apiKey: env.GOOGLE_GENERATIVE_AI_API_KEY!,
      });
      return provider(modelId);
    }
    case "mistral": {
      const provider = createMistral({ apiKey: env.MISTRAL_API_KEY! });
      return provider(modelId);
    }
    case "deepseek": {
      const provider = createOpenAI({
        baseURL: "https://api.deepseek.com/v1",
        apiKey: env.DEEPSEEK_API_KEY!,
      });
      return provider.chat(modelId);
    }
    case "groq": {
      const provider = createOpenAI({
        baseURL: "https://api.groq.com/openai/v1",
        apiKey: env.GROQ_API_KEY!,
      });
      return provider.chat(modelId);
    }
    case "openai_compatible": {
      const provider = createOpenAI({
        baseURL: env.OPENAI_COMPATIBLE_BASE_URL!,
        apiKey: env.OPENAI_COMPATIBLE_API_KEY!,
      });
      return provider.chat(modelId);
    }
    case "ollama": {
      const provider = createOpenAI({
        baseURL: env.OLLAMA_BASE_URL,
        apiKey: "ollama",
      });
      return provider.chat(modelId);
    }
  }
}
