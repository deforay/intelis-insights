export const CATEGORY_CHART_ROW_HEIGHT = 32;
export const CATEGORY_CHART_VERTICAL_PADDING = 82;
export const CATEGORY_CHART_MIN_HEIGHT = 320;
export const CATEGORY_CHART_MAX_EXPANDED_HEIGHT = 720;

export function getCategoryChartHeight(rowCount: number): number {
  return Math.max(
    CATEGORY_CHART_MIN_HEIGHT,
    rowCount * CATEGORY_CHART_ROW_HEIGHT + CATEGORY_CHART_VERTICAL_PADDING,
  );
}

export function getCategoryAxisWidth(
  rows: Record<string, unknown>[],
  key: string,
): number {
  const longest = rows.reduce((max, row) => {
    const value = row[key];
    return Math.max(max, value === null || value === undefined ? 0 : String(value).length);
  }, 0);

  if (longest > 48) return 280;
  if (longest > 36) return 240;
  if (longest > 24) return 200;
  return 150;
}
