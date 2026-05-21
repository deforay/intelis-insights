/**
 * Node: narrate-result.
 *
 * Calls the fast model to produce:
 *   1. A 1-3 sentence natural-language narration of the result
 *   2. 2-3 suggested follow-up questions
 *
 * Both feed the UI directly — the narration becomes the hero text
 * above the bento, the follow-ups become clickable chips beneath the
 * composer. This is the layer that makes the system feel like an
 * AI assistant rather than a SQL form.
 *
 * Runs *after* execute-query (so it has real numbers to reference).
 * Failures here are non-fatal — the rest of the response still
 * renders, just without the narrative.
 */
import { z } from "zod";
import type { GraphStateType, GraphStateUpdate } from "../state";
import { generateStructured } from "@/lib/llm/structured";
import { NARRATE_RESULT_SYSTEM, narrateUserPrompt } from "@/lib/llm/prompts";

const NarrationSchema = z.object({
  narration: z.string().describe("1-3 sentences explaining the result."),
  followUps: z
    .array(z.string())
    .min(0)
    .max(4)
    .describe("Concrete follow-up questions for the analyst to ask next."),
});

export async function narrateResult(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  if (!state.results || !state.sql) return {};

  try {
    const { object } = await generateStructured({
      schema: NarrationSchema,
      schemaName: "result_narration",
      system: NARRATE_RESULT_SYSTEM,
      prompt: narrateUserPrompt({
        question: state.question,
        sql: state.sql,
        assumptions: state.sqlMeta?.assumptions ?? [],
        rowCount: state.results.count,
        columns: state.results.columns,
        sampleRows: state.results.rows.slice(0, 5),
      }),
      modelKind: "fast",
      temperature: 0.3,
      maxOutputTokens: 400,
    });
    return {
      narration: object.narration,
      followUpSuggestions: object.followUps,
    };
  } catch (err) {
    // Non-fatal — let the rest of the response render.
    console.error("[narrate-result] failed:", err);
    return {};
  }
}
