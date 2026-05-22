import { describe, expect, it } from "vitest";
import {
  CATEGORY_CHART_MIN_HEIGHT,
  getCategoryAxisWidth,
  getCategoryChartHeight,
} from "@/lib/chart/display";

describe("category chart display helpers", () => {
  it("keeps expanded category charts from collapsing below the minimum height", () => {
    expect(getCategoryChartHeight(3)).toBe(CATEGORY_CHART_MIN_HEIGHT);
    expect(getCategoryChartHeight(30)).toBeGreaterThan(CATEGORY_CHART_MIN_HEIGHT);
  });

  it("widens the category axis for long labels", () => {
    expect(getCategoryAxisWidth([{ lab: "Short label" }], "lab")).toBe(150);
    expect(
      getCategoryAxisWidth(
        [{ lab: "Laboratoire National de Reference SIDA et IST" }],
        "lab",
      ),
    ).toBe(240);
  });
});
