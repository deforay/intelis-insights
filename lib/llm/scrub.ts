/**
 * Forbidden-column scrubber for prompt-bound text.
 *
 * Applied to any prior-turn SQL or natural-language context before it is
 * threaded into a new LLM prompt. The point isn't to *validate* SQL (the
 * `validate-query` node does that on generator output); it's to ensure that
 * if a user accidentally typed a literal patient identifier in a previous
 * turn, the LLM never sees it on the next turn.
 */
import { FORBIDDEN_PATTERNS } from "@/lib/config/business-rules";

const REPLACEMENT = "[redacted]";

export function scrubForbidden(text: string): string {
  if (!text) return text;
  let scrubbed = text;
  for (const pattern of FORBIDDEN_PATTERNS) {
    scrubbed = scrubbed.replace(pattern, REPLACEMENT);
  }
  return scrubbed;
}

export function scrubConversationBlock(block: string | null): string | null {
  if (!block) return block;
  return scrubForbidden(block);
}
