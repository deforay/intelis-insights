/**
 * Node: generate-sql.
 *
 * Calls the SQL-generation LLM with a structured-output schema. The model
 * returns either a SQL string with assumptions + citations, or a
 * clarification request when it can't answer confidently. Token usage
 * from the response is captured for the audit log.
 *
 * On a retry (state.sqlRetries > 0), the user prompt is rebuilt with the
 * previous SQL and validator error attached.
 */
import { z } from "zod";
import type { GraphStateType, GraphStateUpdate } from "../state";
import type { ClarificationRequest, SqlMeta } from "../types";
import { generateStructured } from "@/lib/llm/structured";
import {
  SQL_GENERATION_SYSTEM,
  sqlGenerationUserPrompt,
  sqlRetryUserPrompt,
} from "@/lib/llm/prompts";
import { scrubConversationBlock } from "@/lib/llm/scrub";

const SqlOutputSchema = z.object({
  sql: z
    .string()
    .describe("A single MySQL SELECT statement. Empty when asking for clarification."),
  confidence: z
    .number()
    .min(0)
    .max(1)
    .describe("Self-reported confidence in the generated SQL."),
  assumptions: z
    .array(z.string())
    .describe(
      "Every default, inference, or scope choice you applied. One short sentence each.",
    ),
  citations: z
    .array(z.string())
    .describe(
      "Snippet ids and table:<name> tags you relied on. Empty array if none.",
    ),
  clarificationNeeded: z
    .object({
      question: z.string(),
      reason: z.string(),
    })
    .nullable()
    .describe(
      "Set only when the question is ambiguous or unanswerable. sql must be empty in that case.",
    ),
});

export async function generateSql(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  const rag = state.ragContext;
  if (!rag) {
    return {
      error: {
        code: "missing_rag_context",
        message: "generate-sql invoked without ragContext",
        stage: "generate-sql",
      },
    };
  }

  const scrubbedConvo = scrubConversationBlock(state.conversationBlock);
  const isRetry = state.error?.stage === "validate-query" && !!state.sql;
  const nextRetryCount = (state.sqlRetries ?? 0) + (isRetry ? 1 : 0);
  const prompt = isRetry
    ? sqlRetryUserPrompt({
        schemaBlock: rag.schemaBlock,
        ragJson: rag.ragJson,
        conversationBlock: scrubbedConvo,
        question: state.question,
        previousSql: state.sql!,
        validationError: state.error!.message,
      })
    : sqlGenerationUserPrompt({
        schemaBlock: rag.schemaBlock,
        ragJson: rag.ragJson,
        conversationBlock: scrubbedConvo,
        question: state.question,
      });

  const result = await generateStructured({
    schema: SqlOutputSchema,
    schemaName: "sql_generation",
    system: SQL_GENERATION_SYSTEM,
    prompt,
    modelKind: "primary",
    temperature: 0,
    maxOutputTokens: 1500,
  });

  const { object, usage, modelId } = result;
  const clarification: ClarificationRequest | null = object.clarificationNeeded
    ? {
        question: object.clarificationNeeded.question,
        reason: object.clarificationNeeded.reason,
      }
    : null;

  const sqlMeta: SqlMeta = {
    confidence: object.confidence,
    assumptions: object.assumptions,
    citations: object.citations,
    clarificationNeeded: clarification,
    tokenUsage: {
      inputTokens: usage.inputTokens ?? null,
      outputTokens: usage.outputTokens ?? null,
      totalTokens: usage.totalTokens ?? null,
    },
    modelId,
  };

  // Confidence gate: a model that hedges below this threshold without
  // asking for clarification is more useful held back than guessing.
  // Synthesise a clarification so the user gets an opportunity to
  // refine instead of seeing wrong-looking SQL run silently.
  const LOW_CONFIDENCE_THRESHOLD = 0.35;
  const effectiveClarification: ClarificationRequest | null =
    clarification ??
    (object.confidence < LOW_CONFIDENCE_THRESHOLD && object.sql.trim()
      ? {
          question:
            "I'm not confident I understood the question. Could you rephrase or add detail (test type, time window, geography)?",
          reason: `Model self-reported confidence ${object.confidence.toFixed(2)} below threshold ${LOW_CONFIDENCE_THRESHOLD}.`,
        }
      : null);

  const finalMeta: SqlMeta = {
    ...sqlMeta,
    clarificationNeeded: effectiveClarification,
  };

  // Clarification path (model asked back OR we held back on low
  // confidence). Not an error — it's a valid response shape. The route
  // handler emits a "clarification" event so the UI can render a
  // dedicated card with the question.
  if (effectiveClarification && (!object.sql.trim() || object.confidence < LOW_CONFIDENCE_THRESHOLD)) {
    return {
      sql: null,
      sqlMeta: finalMeta,
      sqlRetries: nextRetryCount,
      error: null,
    };
  }

  // The model returned no SQL and no clarification — that's an error.
  if (!object.sql.trim()) {
    return {
      sql: null,
      sqlMeta: finalMeta,
      sqlRetries: nextRetryCount,
      error: {
        code: "empty_sql",
        message:
          "SQL generator returned an empty statement without a clarification request",
        stage: "generate-sql",
      },
    };
  }

  return {
    sql: object.sql.trim(),
    sqlMeta: finalMeta,
    sqlRetries: nextRetryCount,
    // Clear any prior validator error so the retry path doesn't loop.
    error: null,
  };
}
