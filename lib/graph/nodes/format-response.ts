/**
 * Node: format-response.
 *
 * Three responsibilities:
 *   1. Build a chart suggestion (heuristic-first, LLM fallback).
 *   2. Distinguish "valid query, zero rows" from upstream errors so the
 *      UI can show a meaningful empty-state message.
 *   3. Pass any upstream error through unchanged — the route handler
 *      uses `state.error` to decide what to render.
 */
import { z } from "zod";
import type { GraphStateType, GraphStateUpdate } from "../state";
import type { ChartSuggestion, ChartType } from "../types";
import {
  applyHeuristic,
  inferConfig,
  profileColumns,
} from "../chart-heuristics";
import { generateStructured } from "@/lib/llm/structured";
import { CHART_SYSTEM, chartUserPrompt } from "@/lib/llm/prompts";

const CHART_TYPES = [
  "table",
  "line",
  "area",
  "bar",
  "horizontal_bar",
  "stacked_bar",
  "pie",
  "donut",
  "scatter",
] as const;

const ChartLlmSchema = z.object({
  recommended: z.enum(CHART_TYPES),
  alternatives: z.array(z.enum(CHART_TYPES)),
  config: z.object({
    xAxis: z.string(),
    yAxis: z.string(),
    series: z.string().nullable(),
    title: z.string(),
  }),
  reasoning: z.string(),
});

export async function formatResponse(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  // If we don't have results, the route will surface state.error directly.
  if (!state.results) return {};

  const profile = profileColumns(state.results);
  const heuristic = applyHeuristic(profile, state.results.count);
  if (heuristic) return { chart: heuristic };

  // Inconclusive — ask the small model.
  try {
    const intentSummary =
      state.intent?.intents.join(", ") ?? "general";
    const { object } = await generateStructured({
      schema: ChartLlmSchema,
      schemaName: "chart_suggestion",
      system: CHART_SYSTEM,
      prompt: chartUserPrompt({
        question: state.question,
        intent: intentSummary,
        rowCount: state.results.count,
        profile,
      }),
      modelKind: "fast",
      temperature: 0,
      maxOutputTokens: 400,
    });
    const chart: ChartSuggestion = {
      recommended: object.recommended as ChartType,
      alternatives: object.alternatives as ChartType[],
      config: object.config,
      reasoning: object.reasoning,
    };
    return { chart };
  } catch {
    // Last-resort fallback: a table.
    return {
      chart: {
        recommended: "table",
        alternatives: ["bar"],
        config: inferConfig(profile, "table"),
        reasoning: "Defaulted to a table because chart suggestion failed.",
      },
    };
  }
}
