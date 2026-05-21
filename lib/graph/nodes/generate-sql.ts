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

  // Clarification request — short-circuit; the API route will surface
  // the question back to the user.
  if (clarification && !object.sql.trim()) {
    return {
      sql: null,
      sqlMeta,
      sqlRetries: nextRetryCount,
      error: {
        code: "clarification_needed",
        message: clarification.question,
        stage: "generate-sql",
      },
    };
  }

  // The model returned no SQL and no clarification — treat as error.
  if (!object.sql.trim()) {
    return {
      sql: null,
      sqlMeta,
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
    sqlMeta,
    sqlRetries: nextRetryCount,
    // Clear any prior validator error so the retry path doesn't loop.
    error: null,
  };
}
