/**
 * Node: parse-question.
 *
 * Determines intent type, base tables to retrieve context for, referenced
 * test types, and whether the question is a follow-up. Pure regex — see
 * `intent-regex.ts` for the rationale. Returns the partial state update
 * downstream nodes (retrieve-context, generate-sql) consume.
 */
import type { GraphStateType, GraphStateUpdate } from "../state";
import { classifyIntent } from "../intent-regex";

export async function parseQuestion(
  state: GraphStateType,
): Promise<GraphStateUpdate> {
  const intent = classifyIntent({
    question: state.question,
    hasConversationHistory: !!state.conversationBlock?.trim(),
  });
  return { intent };
}
