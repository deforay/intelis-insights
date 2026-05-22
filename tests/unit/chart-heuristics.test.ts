import { describe, expect, it } from "vitest";
import {
  applyHeuristic,
  profileColumns,
} from "@/lib/graph/chart-heuristics";
import type { LabQueryResult } from "@/lib/graph/types";

function result(rows: Record<string, unknown>[]): LabQueryResult {
  return {
    columns: rows.length > 0 ? Object.keys(rows[0]) : [],
    rows,
    count: rows.length,
    executionMs: 1,
  };
}

describe("chart heuristics", () => {
  it("does not retain raw sample values in column profiles", () => {
    const profile = profileColumns(
      result([
        { Facility: "Central Hospital", "VL Tests": 10 },
        { Facility: "North Clinic", "VL Tests": 20 },
      ]),
    );

    expect(profile[0]).toEqual({
      name: "Facility",
      type: "categorical",
      distinct: 2,
    });
    expect("sample" in profile[0]).toBe(false);
  });

  it("chooses a line chart for a temporal trend with one measure", () => {
    const profile = profileColumns(
      result([
        { Month: "2026-01", "VL Tests": 10 },
        { Month: "2026-02", "VL Tests": 20 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("line");
    expect(chart.config).toMatchObject({
      xAxis: "Month",
      yAxis: "VL Tests",
      series: null,
    });
  });

  it("chooses an area chart for temporal trends with a series dimension", () => {
    const profile = profileColumns(
      result([
        { Month: "2026-01", Province: "A", "VL Tests": 10 },
        { Month: "2026-01", Province: "B", "VL Tests": 20 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("area");
    expect(chart.config).toMatchObject({
      xAxis: "Month",
      yAxis: "VL Tests",
      series: "Province",
    });
  });

  it("chooses a bar chart for categorical counts", () => {
    const profile = profileColumns(
      result([
        { Province: "A", "VL Tests": 10 },
        { Province: "B", "VL Tests": 20 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("bar");
    expect(chart.config).toMatchObject({
      xAxis: "Province",
      yAxis: "VL Tests",
    });
  });

  it("chooses a horizontal bar chart for many categories", () => {
    const rows = Array.from({ length: 13 }, (_, i) => ({
      Facility: `Facility ${i + 1}`,
      "VL Tests": i + 1,
    }));

    const chart = applyHeuristic(profileColumns(result(rows)), rows.length);
    expect(chart.recommended).toBe("horizontal_bar");
  });

  it("chooses a donut chart only for small percentage/rate category splits", () => {
    const profile = profileColumns(
      result([
        { Sex: "Female", "Suppression Rate (%)": 92 },
        { Sex: "Male", "Suppression Rate (%)": 88 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("donut");
  });

  it("chooses a stacked bar chart for two categorical dimensions and one measure", () => {
    const profile = profileColumns(
      result([
        { Province: "A", Sex: "Female", "VL Tests": 10 },
        { Province: "A", Sex: "Male", "VL Tests": 20 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("stacked_bar");
    expect(chart.config).toMatchObject({
      xAxis: "Province",
      yAxis: "VL Tests",
      series: "Sex",
    });
  });

  it("chooses a scatter chart for two numeric dimensions", () => {
    const profile = profileColumns(
      result([
        { "Median TAT (Days)": 4, "Rejection Rate (%)": 2 },
        { "Median TAT (Days)": 6, "Rejection Rate (%)": 5 },
      ]),
    );

    const chart = applyHeuristic(profile, 2);
    expect(chart.recommended).toBe("scatter");
  });

  it("falls back to table for high-dimensional results", () => {
    const profile = profileColumns(
      result([
        {
          A: "x",
          B: "y",
          C: "z",
          D: "q",
          E: "r",
          F: "s",
          G: "t",
        },
      ]),
    );

    const chart = applyHeuristic(profile, 1);
    expect(chart.recommended).toBe("table");
  });
});
