/**
 * Embedding provider abstraction.
 *
 * Wraps the Vercel AI SDK so the rest of the codebase doesn't care whether
 * embeddings come from OpenAI, Mistral, an OpenAI-compatible endpoint, or
 * a local Ollama. Pick once at startup via `EMBEDDINGS_PROVIDER`.
 *
 * The dimension is determined at runtime by probing the configured model;
 * it's exposed so `ensureCollection` can create the Qdrant collection with
 * the correct vector size.
 */
import { createOpenAI } from "@ai-sdk/openai";
import { mistral } from "@ai-sdk/mistral";
import { embed, embedMany, type EmbeddingModel } from "ai";
import { env } from "@/lib/config/env";

function buildEmbeddingModel(): EmbeddingModel {
  switch (env.EMBEDDINGS_PROVIDER) {
    case "openai": {
      const provider = createOpenAI({ apiKey: env.OPENAI_API_KEY });
      return provider.embedding(env.EMBEDDINGS_MODEL);
    }
    case "mistral":
      return mistral.textEmbedding(env.EMBEDDINGS_MODEL);
    case "openai_compatible": {
      if (!env.OPENAI_COMPATIBLE_BASE_URL || !env.OPENAI_COMPATIBLE_API_KEY) {
        throw new Error(
          "EMBEDDINGS_PROVIDER=openai_compatible requires OPENAI_COMPATIBLE_BASE_URL and OPENAI_COMPATIBLE_API_KEY",
        );
      }
      const provider = createOpenAI({
        baseURL: env.OPENAI_COMPATIBLE_BASE_URL,
        apiKey: env.OPENAI_COMPATIBLE_API_KEY,
      });
      return provider.embedding(env.EMBEDDINGS_MODEL);
    }
    case "ollama": {
      const provider = createOpenAI({
        baseURL: env.OLLAMA_BASE_URL,
        apiKey: "ollama",
      });
      return provider.embedding(env.EMBEDDINGS_MODEL);
    }
  }
}

let cachedModel: EmbeddingModel | null = null;
function model(): EmbeddingModel {
  if (!cachedModel) cachedModel = buildEmbeddingModel();
  return cachedModel;
}

let cachedDim: number | null = null;

/**
 * Probe the embedder once to learn the vector dimension. The result is
 * cached for the lifetime of the process.
 */
export async function getEmbeddingDimension(): Promise<number> {
  if (cachedDim !== null) return cachedDim;
  const { embedding } = await embed({ model: model(), value: "dim-probe" });
  cachedDim = embedding.length;
  return cachedDim;
}

export async function embedOne(text: string): Promise<number[]> {
  const { embedding } = await embed({ model: model(), value: text });
  return embedding;
}

export async function embedBatch(texts: string[]): Promise<number[][]> {
  if (texts.length === 0) return [];
  const { embeddings } = await embedMany({ model: model(), values: texts });
  return embeddings;
}
