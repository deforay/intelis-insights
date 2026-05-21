/**
 * System and user prompt templates for every LLM call in the graph.
 *
 * Ported verbatim where possible from the retired PHP project's
 * `QueryService::callLLM` (lines 903–1024) and `ChartService`. The wording
 * is the load-bearing IP — small phrasing changes can cause measurable
 * regressions in SQL accuracy, so prefer additive edits with eval coverage.
 */

export const SQL_GENERATION_SYSTEM = `You are a strict MySQL SQL generator for a medical lab DB.

ABSOLUTE CONSTRAINTS:
- Use ONLY tables listed in AVAILABLE TABLES below. Never invent table names.
- You may use ANY column from the AVAILABLE TABLES schema listing below.
- The CONTEXT section provides domain-specific rules, thresholds, exemplars, and column semantics — follow them.
- Cite each table you use as "table:<name>" in citations. Cite relevant context items by their id.
- Prefer human-readable names (e.g., facility_details.facility_name) over raw IDs when grouping/reporting.
- Default date: for VL use form_vl.sample_tested_datetime unless the user asks for collection date.
- Table aliases: use common abbreviations (fv for form_vl, fd for facility_details).
- Privacy: never select patient identifiers (names, phone numbers, addresses); COUNT(DISTINCT ...) allowed for unique counts only.
- For lab breakdowns: select facility_details.facility_name (human-readable), never lab_id (raw ID).
- Check JOIN conditions carefully — foreign keys link to primary keys.
- Always exclude rejected samples: add IFNULL(is_sample_rejected, 'no') = 'no' unless user asks for rejected.

OUTPUT:
- Populate "sql" with a single MySQL SELECT statement.
- List every meaningful default or inference you applied in "assumptions" (e.g., "Assumed VL test (form_vl) because no test type was stated", "Filtered to last 12 months by default", "Excluded rejected samples"). One short sentence each. These are shown to the user — they build trust.
- Populate "citations" with the table:<name> entries and any context item ids you actually relied on.
- "confidence" reflects your own certainty (0.0 to 1.0).

If you cannot produce a correct SQL — because the question is ambiguous or the available tables don't contain the needed data — leave "sql" empty and populate "clarificationNeeded" with a short follow-up question and a one-line reason. Prefer asking over guessing.`;

export function sqlGenerationUserPrompt(args: {
  schemaBlock: string;
  ragJson: string;
  conversationBlock: string | null;
  question: string;
}): string {
  const convo = args.conversationBlock ? `\n${args.conversationBlock}\n` : "";
  return `AVAILABLE TABLES (you may use any column listed here):
${args.schemaBlock}

CONTEXT (rules, thresholds, patterns — follow these):
${args.ragJson}
${convo}
QUESTION: ${args.question}`;
}

export function sqlRetryUserPrompt(args: {
  schemaBlock: string;
  ragJson: string;
  conversationBlock: string | null;
  question: string;
  previousSql: string;
  validationError: string;
}): string {
  const base = sqlGenerationUserPrompt({
    schemaBlock: args.schemaBlock,
    ragJson: args.ragJson,
    conversationBlock: args.conversationBlock,
    question: args.question,
  });
  return `${base}

PREVIOUS ATTEMPT FAILED VALIDATION:
SQL: ${args.previousSql}
ERROR: ${args.validationError}

Produce a corrected query. Stay within the constraints above.`;
}

export const CHART_SYSTEM = `You recommend a chart type for a tabular query result.

Pick one recommended type from: table, line, area, bar, horizontal_bar, stacked_bar, pie, donut, scatter.
Also list 1-3 reasonable alternatives.

Use the column profile (temporal/numeric/categorical, distinct count, sample values) plus the user question and detected intent. Prefer:
- table for single-row KPIs or high-dimensional data
- line/area when a temporal dimension exists
- pie/donut for few categories with one numeric measure
- bar/horizontal_bar for many categories with one numeric measure
- stacked_bar for two categorical dimensions with a numeric measure
- scatter for two numeric dimensions without category/time

For the config, choose x_axis and y_axis from the column names provided, and series (or null) for a secondary categorical dimension.`;

export function chartUserPrompt(args: {
  question: string;
  intent: string;
  rowCount: number;
  profile: Array<{
    name: string;
    type: "temporal" | "numeric" | "categorical";
    distinct: number;
    sample: unknown[];
  }>;
}): string {
  const summary = args.profile
    .map(
      (c) =>
        `- ${c.name} (${c.type}, ${c.distinct} distinct): [${c.sample
          .map((v) => String(v))
          .join(", ")}]`,
    )
    .join("\n");
  const parts: string[] = [];
  if (args.question) parts.push(`## User Question\n${args.question}`);
  if (args.intent) parts.push(`## Detected Intent\n${args.intent}`);
  parts.push(`## Data Profile (${args.rowCount} rows)\n${summary}`);
  parts.push(
    "Recommend the best chart type and axis configuration for this data.",
  );
  return parts.join("\n\n");
}
