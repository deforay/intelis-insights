"use client";

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import type { ChartSuggestion, LabQueryResult } from "@/lib/graph/types";

const COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
];

const TOOLTIP_STYLES = {
  contentStyle: {
    background: "var(--popover)",
    border: "1px solid var(--border)",
    borderRadius: "8px",
    fontSize: "11px",
  },
  labelStyle: { color: "var(--popover-foreground)", fontWeight: 500 },
};

export function ChartRenderer({
  chart,
  result,
}: {
  chart: ChartSuggestion;
  result: LabQueryResult;
}) {
  const data = result.rows.map((r) => ({ ...r }));
  const { xAxis, yAxis, series } = chart.config;

  if (chart.recommended === "table" || !yAxis) return null;

  const tickStyle = { fill: "var(--muted-foreground)", fontSize: 11 };
  const gridStroke = "var(--border)";

  switch (chart.recommended) {
    case "line":
    case "area": {
      const Chart = chart.recommended === "area" ? AreaChart : LineChart;
      const Series = chart.recommended === "area" ? Area : Line;
      const seriesKeys = series ? distinctValues(data, series) : [yAxis];
      const pivoted = series ? pivot(data, xAxis, series, yAxis) : data;
      return (
        <ResponsiveContainer width="100%" height={300}>
          <Chart data={pivoted}>
            <CartesianGrid stroke={gridStroke} strokeDasharray="3 3" />
            <XAxis dataKey={xAxis} stroke={gridStroke} tick={tickStyle} />
            <YAxis stroke={gridStroke} tick={tickStyle} />
            <Tooltip {...TOOLTIP_STYLES} />
            {seriesKeys.length > 1 && <Legend wrapperStyle={{ fontSize: 11 }} />}
            {seriesKeys.map((key, i) => (
              <Series
                key={String(key)}
                type="monotone"
                dataKey={String(key)}
                stroke={COLORS[i % COLORS.length]}
                fill={COLORS[i % COLORS.length]}
                fillOpacity={0.2}
                strokeWidth={2}
                dot={false}
              />
            ))}
          </Chart>
        </ResponsiveContainer>
      );
    }

    case "bar":
    case "horizontal_bar": {
      const layout = chart.recommended === "horizontal_bar" ? "vertical" : "horizontal";
      return (
        <ResponsiveContainer width="100%" height={300}>
          <BarChart data={data} layout={layout}>
            <CartesianGrid stroke={gridStroke} strokeDasharray="3 3" />
            {layout === "horizontal" ? (
              <>
                <XAxis dataKey={xAxis} stroke={gridStroke} tick={tickStyle} />
                <YAxis stroke={gridStroke} tick={tickStyle} />
              </>
            ) : (
              <>
                <XAxis type="number" stroke={gridStroke} tick={tickStyle} />
                <YAxis
                  dataKey={xAxis}
                  type="category"
                  stroke={gridStroke}
                  tick={tickStyle}
                  width={120}
                />
              </>
            )}
            <Tooltip {...TOOLTIP_STYLES} />
            <Bar dataKey={yAxis} fill={COLORS[0]} radius={[4, 4, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      );
    }

    case "stacked_bar": {
      const seriesKeys = series ? distinctValues(data, series) : [yAxis];
      const pivoted = series ? pivot(data, xAxis, series, yAxis) : data;
      return (
        <ResponsiveContainer width="100%" height={300}>
          <BarChart data={pivoted}>
            <CartesianGrid stroke={gridStroke} strokeDasharray="3 3" />
            <XAxis dataKey={xAxis} stroke={gridStroke} tick={tickStyle} />
            <YAxis stroke={gridStroke} tick={tickStyle} />
            <Tooltip {...TOOLTIP_STYLES} />
            <Legend wrapperStyle={{ fontSize: 11 }} />
            {seriesKeys.map((key, i) => (
              <Bar
                key={String(key)}
                dataKey={String(key)}
                stackId="a"
                fill={COLORS[i % COLORS.length]}
              />
            ))}
          </BarChart>
        </ResponsiveContainer>
      );
    }

    case "pie":
    case "donut": {
      return (
        <ResponsiveContainer width="100%" height={300}>
          <PieChart>
            <Pie
              data={data}
              dataKey={yAxis}
              nameKey={xAxis}
              outerRadius={chart.recommended === "donut" ? 110 : 120}
              innerRadius={chart.recommended === "donut" ? 65 : 0}
              label={{ fontSize: 11 }}
            >
              {data.map((_, i) => (
                <Cell key={i} fill={COLORS[i % COLORS.length]} />
              ))}
            </Pie>
            <Tooltip {...TOOLTIP_STYLES} />
            <Legend wrapperStyle={{ fontSize: 11 }} />
          </PieChart>
        </ResponsiveContainer>
      );
    }

    case "scatter": {
      return (
        <ResponsiveContainer width="100%" height={300}>
          <ScatterChart>
            <CartesianGrid stroke={gridStroke} strokeDasharray="3 3" />
            <XAxis
              dataKey={xAxis}
              type="number"
              name={xAxis}
              stroke={gridStroke}
              tick={tickStyle}
            />
            <YAxis
              dataKey={yAxis}
              type="number"
              name={yAxis}
              stroke={gridStroke}
              tick={tickStyle}
            />
            <Tooltip {...TOOLTIP_STYLES} cursor={{ strokeDasharray: "3 3" }} />
            <Scatter data={data} fill={COLORS[0]} />
          </ScatterChart>
        </ResponsiveContainer>
      );
    }

    default:
      return null;
  }
}

function distinctValues(
  data: Record<string, unknown>[],
  key: string,
): string[] {
  const set = new Set<string>();
  for (const row of data) {
    const v = row[key];
    if (v !== null && v !== undefined) set.add(String(v));
  }
  return Array.from(set);
}

function pivot(
  data: Record<string, unknown>[],
  xKey: string,
  seriesKey: string,
  valueKey: string,
): Record<string, unknown>[] {
  const byX = new Map<string, Record<string, unknown>>();
  for (const row of data) {
    const x = String(row[xKey]);
    const series = String(row[seriesKey]);
    const value = row[valueKey];
    if (!byX.has(x)) byX.set(x, { [xKey]: row[xKey] });
    byX.get(x)![series] = value;
  }
  return Array.from(byX.values());
}
