/**
 * Chart-type heuristics + column profiling.
 *
 * Ported from the retired `ChartService` (lines 168–241). Heuristics
 * return a deterministic chart suggestion when the result shape is
 * obviously a time series, a KPI, a single-dimension bar, etc. When
 * inconclusive, the node falls back to an LLM call.
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
  sample: unknown[];
}

const TEMPORAL_NAME = /(date|time|month|year|quarter|week)/i;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})?/;

export function profileColumns(result: LabQueryResult): ColumnProfile[] {
  return result.columns.map((name) => {
    const values = result.rows.map((row) => row[name]);
    const nonNull = values.filter((v) => v !== null && v !== undefined);
    const distinct = new Set(nonNull.map((v) => String(v))).size;
    const sample = uniqueSample(nonNull, 5);
    return { name, type: detectType(name, nonNull), distinct, sample };
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

function uniqueSample(values: unknown[], n: number): unknown[] {
  const seen = new Set<string>();
  const out: unknown[] = [];
  for (const v of values) {
    const key = String(v);
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(v);
    if (out.length >= n) break;
  }
  return out;
}

export function applyHeuristic(
  profile: ColumnProfile[],
  rowCount: number,
): ChartSuggestion | null {
  if (profile.length === 0 || rowCount === 0) {
    return build("table", ["bar"], inferConfig(profile, "table"), "No rows to chart.");
  }

  const temporal = profile.filter((p) => p.type === "temporal");
  const numeric = profile.filter((p) => p.type === "numeric");
  const categorical = profile.filter((p) => p.type === "categorical");
  const colCount = profile.length;

  if (rowCount === 1 && colCount <= 2) {
    return build(
      "table",
      ["bar"],
      inferConfig(profile, "table"),
      "Single-row result best shown as a KPI or table.",
    );
  }

  if (temporal.length > 0 && numeric.length > 0) {
    const multipleSeries = numeric.length > 1 || categorical.length > 0;
    return build(
      multipleSeries ? "area" : "line",
      ["area", "bar", "table"],
      inferConfig(profile, multipleSeries ? "area" : "line"),
      "Temporal dimension detected — line/area chart is appropriate.",
    );
  }

  if (categorical.length === 1 && numeric.length === 1) {
    if (rowCount <= 7) {
      return build(
        "pie",
        ["donut", "bar", "horizontal_bar"],
        inferConfig(profile, "pie"),
        "Single categorical dimension with few categories suits a pie chart.",
      );
    }
    return build(
      "bar",
      ["horizontal_bar", "table"],
      inferConfig(profile, "bar"),
      "Single categorical dimension with many categories suits a bar chart.",
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
    return build(
      "stacked_bar",
      ["horizontal_bar", "table"],
      inferConfig(profile, "stacked_bar"),
      "Multiple categorical dimensions suit a stacked bar chart.",
    );
  }

  if (colCount > 6) {
    return build(
      "table",
      ["bar"],
      inferConfig(profile, "table"),
      "High-dimensional data is best shown as a table.",
    );
  }

  return null;
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

function build(
  recommended: ChartType,
  alternatives: ChartType[],
  config: ChartConfig,
  reasoning: string,
): ChartSuggestion {
  return { recommended, alternatives, config, reasoning };
}
