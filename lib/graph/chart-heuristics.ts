/**
 * Chart-type heuristics + column profiling.
 *
 * Ported from the retired `ChartService` (lines 168–241). Heuristics
 * return a deterministic chart suggestion when the result shape is
 * obviously a time series, a KPI, a single-dimension bar, etc. This module
 * never prepares raw sample values for an external model; all profiling is
 * local and reduced to safe metadata.
 */
import type {
  ChartConfig,
  ChartSuggestion,
  ChartType,
  LabQueryResult,
} from "./types";

export type ColumnType = "temporal" | "numeric" | "categorical";

export interface ColumnProfile {
  name: string;
  type: ColumnType;
  distinct: number;
}

const TEMPORAL_NAME = /(date|time|month|year|quarter|week)/i;
const PERCENT_NAME = /(%|percent|percentage|rate|ratio|proportion|share)/i;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?/;

export function profileColumns(result: LabQueryResult): ColumnProfile[] {
  return result.columns.map((name) => {
    const values = result.rows.map((row) => row[name]);
    const nonNull = values.filter((v) => v !== null && v !== undefined);
    const distinct = new Set(nonNull.map((v) => String(v))).size;
    return { name, type: detectType(name, nonNull), distinct };
  });
}

function detectType(name: string, values: unknown[]): ColumnType {
  if (TEMPORAL_NAME.test(name)) return "temporal";
  if (values.length > 0 && values.every(isDateLike)) return "temporal";
  if (values.length > 0 && values.every(isNumericLike)) return "numeric";
  return "categorical";
}

function isDateLike(v: unknown): boolean {
  if (v instanceof Date) return true;
  if (typeof v === "string") return ISO_DATE.test(v);
  return false;
}

function isNumericLike(v: unknown): boolean {
  if (typeof v === "number") return Number.isFinite(v);
  if (typeof v === "string" && v.trim() !== "") return Number.isFinite(Number(v));
  return false;
}

export function applyHeuristic(
  profile: ColumnProfile[],
  rowCount: number,
): ChartSuggestion {
  if (profile.length === 0 || rowCount === 0) {
    return build("table", ["bar"], inferConfig(profile, "table"), "No rows to chart.");
  }

  const temporal = profile.filter((p) => p.type === "temporal");
  const numeric = profile.filter((p) => p.type === "numeric");
  const categorical = profile.filter((p) => p.type === "categorical");
  const colCount = profile.length;
  const primaryMeasure = numeric[0];

  if (rowCount === 1 && colCount <= 2) {
    return build(
      "table",
      ["bar"],
      inferConfig(profile, "table"),
      "Single-row result best shown as a KPI or table.",
    );
  }

  if (temporal.length > 0 && numeric.length > 0) {
    const multipleSeries = categorical.some((c) => c.distinct <= 12);
    return build(
      multipleSeries ? "area" : "line",
      ["area", "bar", "table"],
      inferConfig(profile, multipleSeries ? "area" : "line"),
      "Temporal dimension detected — line/area chart is appropriate.",
    );
  }

  if (categorical.length === 1 && numeric.length === 1) {
    if (rowCount <= 6 && primaryMeasure && isPercentMetric(primaryMeasure.name)) {
      return build(
        "donut",
        ["bar", "horizontal_bar", "table"],
        inferConfig(profile, "donut"),
        "Few categories with a percentage/rate measure suit a donut chart.",
      );
    }
    const chartType: ChartType = rowCount > 12 ? "horizontal_bar" : "bar";
    return build(
      chartType,
      chartType === "bar" ? ["horizontal_bar", "table"] : ["bar", "table"],
      inferConfig(profile, chartType),
      "Single categorical dimension with one measure suits a bar chart.",
    );
  }

  if (categorical.length === 0 && temporal.length === 0 && numeric.length === 2) {
    return build(
      "scatter",
      ["table"],
      inferConfig(profile, "scatter"),
      "Two numeric dimensions suggest a scatter plot.",
    );
  }

  if (categorical.length >= 2 && numeric.length > 0) {
    if (categorical[1].distinct > 12) {
      return build(
        "table",
        ["horizontal_bar", "bar"],
        inferConfig(profile, "table"),
        "Too many series categories for a readable stacked chart.",
      );
    }
    return build(
      "stacked_bar",
      ["horizontal_bar", "table"],
      inferConfig(profile, "stacked_bar"),
      "Multiple categorical dimensions suit a stacked bar chart.",
    );
  }

  if (colCount > 6 || numeric.length === 0) {
    return build(
      "table",
      ["bar"],
      inferConfig(profile, "table"),
      "High-dimensional data is best shown as a table.",
    );
  }

  return build(
    "table",
    ["bar"],
    inferConfig(profile, "table"),
    "No deterministic chart rule matched this result shape.",
  );
}

export function inferConfig(
  profile: ColumnProfile[],
  chartType: ChartType,
): ChartConfig {
  const temporal = profile.filter((p) => p.type === "temporal").map((p) => p.name);
  const numeric = profile.filter((p) => p.type === "numeric").map((p) => p.name);
  const categorical = profile.filter((p) => p.type === "categorical").map((p) => p.name);

  let xAxis = temporal[0] ?? categorical[0] ?? numeric[0] ?? "";
  let yAxis = numeric[0] ?? "";

  if (chartType === "scatter" && numeric.length >= 2) {
    xAxis = numeric[0];
    yAxis = numeric[1];
  }
  if (xAxis === yAxis && numeric.length > 1) {
    yAxis = numeric[1];
  }

  let series: string | null = null;
  if (
    categorical.length > 0 &&
    (chartType === "stacked_bar" ||
      chartType === "area" ||
      chartType === "line")
  ) {
    series = categorical.find((c) => c !== xAxis) ?? null;
  }

  return { xAxis, yAxis, series, title: "" };
}

function isPercentMetric(name: string): boolean {
  return PERCENT_NAME.test(name);
}

function build(
  recommended: ChartType,
  alternatives: ChartType[],
  config: ChartConfig,
  reasoning: string,
): ChartSuggestion {
  return { recommended, alternatives, config, reasoning };
}
