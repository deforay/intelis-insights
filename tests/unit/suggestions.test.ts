import { describe, expect, it } from "vitest";
import {
  EMPTY_STATE_SUGGESTION_POOL,
  getEmptyStateSuggestions,
  getSuggestionCategory,
} from "@/components/chat/suggestions";

describe("empty-state suggestion rotation", () => {
  it("has a broad enough curated pool for repeated shuffles", () => {
    expect(EMPTY_STATE_SUGGESTION_POOL.length).toBeGreaterThanOrEqual(80);
    expect(new Set(EMPTY_STATE_SUGGESTION_POOL).size).toBe(
      EMPTY_STATE_SUGGESTION_POOL.length,
    );
  });

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

  it("labels common suggestion categories", () => {
    expect(getSuggestionCategory("Show rejected sample rate by testing lab")).toBe(
      "Quality",
    );
    expect(getSuggestionCategory("Average turnaround time by province")).toBe(
      "TAT",
    );
    expect(getSuggestionCategory("Show suppression rate by province")).toBe(
      "Suppression",
    );
    expect(getSuggestionCategory("Show VL testing volume this year")).toBe(
      "Volume",
    );
  });
});
