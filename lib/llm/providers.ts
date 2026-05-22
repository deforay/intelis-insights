/**
 * Language-model provider factory.
 *
 * Picks a provider once at startup based on `LLM_PROVIDER` and returns a
 * Vercel AI SDK `LanguageModel` for `getLanguageModel(kind)`. Two model
 * slots are supported:
 *   - "primary" — the SQL generator (`LLM_MODEL`)
 *   - "fast"    — a smaller/cheaper model used for secondary tasks like
 *                 result narration (`LLM_MODEL_FAST`)
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

/**
 * Strategy for getting structured output from the model:
 *   - "auto": let the SDK pick (uses response_format: json_schema when
 *             available). Right for OpenAI / Anthropic / Google /
 *             Mistral / newer Groq models.
 *   - "tool": wrap the schema as a forced tool call and parse from
 *             tool args. Right for DeepSeek and other OpenAI-compatible
 *             providers that support function calling but not
 *             response_format: json_schema.
 *   - "json": use response_format: json_object (no schema enforcement)
 *             and Zod-parse the output. Most conservative — works
 *             with Ollama and minimal OpenAI-compatible gateways.
 */
export type StructuredMode = "auto" | "tool" | "json";

export function getStructuredMode(): StructuredMode {
  switch (env.LLM_PROVIDER) {
    case "openai":
    case "anthropic":
    case "google":
    case "mistral":
    case "groq":
      return "auto";
    case "deepseek":
      return "tool";
    case "openai_compatible":
    case "ollama":
      return "json";
  }
}

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
