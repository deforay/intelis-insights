import { z } from "zod";

const LlmProvider = z.enum([
  "openai",
  "anthropic",
  "google",
  "mistral",
  "deepseek",
  "groq",
  "openai_compatible",
  "ollama",
]);
const EmbeddingsProvider = z.enum([
  "openai",
  "mistral",
  "openai_compatible",
  "ollama",
]);

const EnvSchema = z.object({
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),

  // Auth.js v5 (AUTH_* is the canonical name in v5)
  AUTH_SECRET: z.string().min(32, "AUTH_SECRET must be at least 32 chars"),
  AUTH_URL: z.url().optional(),

  // App database (Postgres) — sessions, audit, users, LangGraph checkpoints
  APP_DB_URL: z.string().min(1),

  // Lab database (external InteLIS MySQL — read-only)
  LAB_DB_HOST: z.string().min(1),
  LAB_DB_PORT: z.coerce.number().int().positive().default(3306),
  LAB_DB_NAME: z.string().min(1),
  LAB_DB_USER: z.string().min(1),
  LAB_DB_PASSWORD: z.string(),

  // Qdrant vector DB
  QDRANT_URL: z.url(),
  QDRANT_API_KEY: z.string().optional(),
  QDRANT_COLLECTION: z.string().default("intelis_insights"),

  // LLM provider selection
  LLM_PROVIDER: LlmProvider.default("openai"),
  LLM_MODEL: z.string().default("gpt-4o"),
  LLM_MODEL_FAST: z.string().default("gpt-4o-mini"),

  // Provider credentials (each optional; required only when selected)
  OPENAI_API_KEY: z.string().optional(),
  ANTHROPIC_API_KEY: z.string().optional(),
  GOOGLE_GENERATIVE_AI_API_KEY: z.string().optional(),
  MISTRAL_API_KEY: z.string().optional(),
  DEEPSEEK_API_KEY: z.string().optional(),
  GROQ_API_KEY: z.string().optional(),

  // Generic OpenAI-compatible endpoint (Together, Fireworks, OpenRouter,
  // self-hosted vLLM / LiteLLM, etc.)
  OPENAI_COMPATIBLE_BASE_URL: z.url().optional(),
  OPENAI_COMPATIBLE_API_KEY: z.string().optional(),

  OLLAMA_BASE_URL: z.url().default("http://localhost:11434/v1"),

  // Embeddings
  EMBEDDINGS_PROVIDER: EmbeddingsProvider.default("openai"),
  EMBEDDINGS_MODEL: z.string().default("text-embedding-3-small"),

  // Observability (LangFuse — optional, self-hosted or cloud)
  LANGFUSE_PUBLIC_KEY: z.string().optional(),
  LANGFUSE_SECRET_KEY: z.string().optional(),
  LANGFUSE_HOST: z.url().optional(),
});

type Env = z.infer<typeof EnvSchema>;

function loadEnv(): Env {
  const parsed = EnvSchema.safeParse(process.env);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .map((i) => `  - ${i.path.join(".")}: ${i.message}`)
      .join("\n");
    throw new Error(`Invalid environment configuration:\n${issues}`);
  }

  const e = parsed.data;

  const llmRequirements: Record<typeof e.LLM_PROVIDER, () => string | null> = {
    openai: () => (e.OPENAI_API_KEY ? null : "OPENAI_API_KEY"),
    anthropic: () => (e.ANTHROPIC_API_KEY ? null : "ANTHROPIC_API_KEY"),
    google: () =>
      e.GOOGLE_GENERATIVE_AI_API_KEY ? null : "GOOGLE_GENERATIVE_AI_API_KEY",
    mistral: () => (e.MISTRAL_API_KEY ? null : "MISTRAL_API_KEY"),
    deepseek: () => (e.DEEPSEEK_API_KEY ? null : "DEEPSEEK_API_KEY"),
    groq: () => (e.GROQ_API_KEY ? null : "GROQ_API_KEY"),
    openai_compatible: () =>
      !e.OPENAI_COMPATIBLE_BASE_URL
        ? "OPENAI_COMPATIBLE_BASE_URL"
        : !e.OPENAI_COMPATIBLE_API_KEY
        ? "OPENAI_COMPATIBLE_API_KEY"
        : null,
    ollama: () => null,
  };
  const missingLlm = llmRequirements[e.LLM_PROVIDER]();
  if (missingLlm) {
    throw new Error(`LLM_PROVIDER=${e.LLM_PROVIDER} requires ${missingLlm}`);
  }

  const embedRequirements: Record<
    typeof e.EMBEDDINGS_PROVIDER,
    () => string | null
  > = {
    openai: () => (e.OPENAI_API_KEY ? null : "OPENAI_API_KEY"),
    mistral: () => (e.MISTRAL_API_KEY ? null : "MISTRAL_API_KEY"),
    openai_compatible: () =>
      !e.OPENAI_COMPATIBLE_BASE_URL
        ? "OPENAI_COMPATIBLE_BASE_URL"
        : !e.OPENAI_COMPATIBLE_API_KEY
        ? "OPENAI_COMPATIBLE_API_KEY"
        : null,
    ollama: () => null,
  };
  const missingEmbed = embedRequirements[e.EMBEDDINGS_PROVIDER]();
  if (missingEmbed) {
    throw new Error(
      `EMBEDDINGS_PROVIDER=${e.EMBEDDINGS_PROVIDER} requires ${missingEmbed}`,
    );
  }

  return e;
}

export const env = loadEnv();
export type { Env };
