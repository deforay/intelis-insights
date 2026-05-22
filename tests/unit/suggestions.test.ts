import { describe, expect, it } from "vitest";
import {
  EMPTY_STATE_SUGGESTION_POOL,
  getEmptyStateSuggestions,
} from "@/components/chat/suggestions";

describe("empty-state suggestion rotation", () => {
  it("returns four curated suggestions", () => {
    const suggestions = getEmptyStateSuggestions(0.42);

    expect(suggestions).toHaveLength(4);
    expect(new Set(suggestions).size).toBe(4);
    expect(
      suggestions.every((suggestion) =>
        EMPTY_STATE_SUGGESTION_POOL.includes(
          suggestion as (typeof EMPTY_STATE_SUGGESTION_POOL)[number],
        ),
      ),
    ).toBe(true);
  });

  it("is deterministic for the same seed", () => {
    expect(getEmptyStateSuggestions(0.25)).toEqual(
      getEmptyStateSuggestions(0.25),
    );
  });

  it("rotates to a different set for a different seed", () => {
    expect(getEmptyStateSuggestions(0.1)).not.toEqual(
      getEmptyStateSuggestions(0.8),
    );
  });
});
