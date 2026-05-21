import { z } from "zod";

const LlmProvider = z.enum(["openai", "anthropic", "google", "ollama"]);
const EmbeddingsProvider = z.enum(["openai", "ollama"]);

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
  LLM_MODEL_INTENT: z.string().default("gpt-4o-mini"),

  // Provider credentials (each optional; required only when selected)
  OPENAI_API_KEY: z.string().optional(),
  ANTHROPIC_API_KEY: z.string().optional(),
  GOOGLE_GENERATIVE_AI_API_KEY: z.string().optional(),
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
  if (e.LLM_PROVIDER === "openai" && !e.OPENAI_API_KEY) {
    throw new Error("LLM_PROVIDER=openai requires OPENAI_API_KEY");
  }
  if (e.LLM_PROVIDER === "anthropic" && !e.ANTHROPIC_API_KEY) {
    throw new Error("LLM_PROVIDER=anthropic requires ANTHROPIC_API_KEY");
  }
  if (e.LLM_PROVIDER === "google" && !e.GOOGLE_GENERATIVE_AI_API_KEY) {
    throw new Error("LLM_PROVIDER=google requires GOOGLE_GENERATIVE_AI_API_KEY");
  }
  if (e.EMBEDDINGS_PROVIDER === "openai" && !e.OPENAI_API_KEY) {
    throw new Error("EMBEDDINGS_PROVIDER=openai requires OPENAI_API_KEY");
  }
  return e;
}

export const env = loadEnv();
export type { Env };
