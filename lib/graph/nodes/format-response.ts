/**
 * Node: format-response.
 *
 * Three responsibilities:
 *   1. Build a deterministic chart suggestion from local result metadata.
 *   2. Distinguish "valid query, zero rows" from upstream errors so the
 *      UI can show a meaningful empty-state message.
 *   3. Pass any upstream error through unchanged — the route handler
 *      uses `state.error` to decide what to render.
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import {
  applyHeuristic,
  profileColumns,
} from "../chart-heuristics";

export async function formatResponse(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  // If we don't have results, the route will surface state.error directly.
  if (!state.results) return {};

  const profile = profileColumns(state.results);
  return { chart: applyHeuristic(profile, state.results.count) };
}
