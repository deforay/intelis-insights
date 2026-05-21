/**
 * Live SQL-execution eval harness.
 *
 * Two fixture shapes:
 *
 *   1. Single-turn — one question through the full pipeline. Asserts on
 *      row count, column count, error state, value ranges, and column
 *      aliasing.
 *
 *   2. Multi-turn — a sequence of questions in one session. Each turn's
 *      assistant content is folded into the next turn's conversation
 *      block (matching what the API route does in production). Turns
 *      can `captureAs` a numeric value and a later turn can assert
 *      `sumNumericApproxEquals` against it. This is what catches the
 *      class of bugs where a follow-up silently changes scope.
 *
 * Gated behind EVAL_LIVE=1 so the cheaper `npm run eval` (LLM-only
 * feature-shape harness) and the default `npm test` don't touch the
 * lab DB.
 *
 * Usage:
 *   docker compose up -d postgres qdrant
 *   npm run eval:live
 *   npm run eval:live -- -t tat-avg-per-lab
 */
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { randomUUID } from "node:crypto";
import { runQuery } from "@/lib/graph/runner";
import type { GraphStateType } from "@/lib/graph/state";
import type { UserContext } from "@/lib/auth/rbac";

// ── Fixture types ────────────────────────────────────────────────────

interface LiveExpectations {
  shouldFail?: boolean;
  errorStageOneOf?: string[];
  minRows?: number;
  maxRows?: number;
  minColumns?: number;
  maxColumns?: number;
  /** Every result column header should be a human alias (title case w/ spaces). */
  columnsMustBeAliased?: boolean;
  /** Every numeric cell across all rows should fall in this range. */
  allNumericValuesInRange?: { min: number; max: number };
}

interface TurnExpectations extends LiveExpectations {
  /** Sum of all numeric columns over all rows must equal a previously captured value (± tolerance). */
  sumNumericApproxEquals?: { ref: string; tolerance: number };
  /** Generated SQL must contain ALL of these substrings (case-sensitive). */
  sqlMustContainKeywords?: string[];
}

interface SingleTurnFixture {
  name: string;
  description?: string;
  question: string;
  liveExpectations?: LiveExpectations;
}

interface MultiTurnFixture {
  name: string;
  description?: string;
  turns: Array<{
    question: string;
    captureAs?: string;
    expect?: TurnExpectations;
  }>;
}

type Fixture = SingleTurnFixture | MultiTurnFixture;

function isMultiTurn(fx: Fixture): fx is MultiTurnFixture {
  return "turns" in fx;
}

// ── Runner ───────────────────────────────────────────────────────────

const FIXTURES: Fixture[] = JSON.parse(
  fs.readFileSync(path.join(__dirname, "fixtures", "queries.json"), "utf-8"),
);

const NATIONAL_USER: UserContext = {
  userId: "eval-runner",
  accessLevel: "national",
  allowedProvinces: [],
  allowedDistricts: [],
};

const CONVERSATION_WINDOW_TURNS = 4;

async function runOneTurn(
  question: string,
  sessionId: string,
  conversationBlock: string | null,
): Promise<GraphStateType> {
  const { events, final } = await runQuery({
    question,
    sessionId,
    userContext: NATIONAL_USER,
    conversationBlock,
  });
  // Drain the event stream — only the final state is asserted on.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  for await (const _ of events) {
    // intentionally ignored
  }
  return await final;
}

function buildConversationBlock(
  history: Array<{ role: "user" | "assistant"; content: string }>,
): string | null {
  if (history.length === 0) return null;
  const tail = history.slice(-CONVERSATION_WINDOW_TURNS * 2);
  return tail
    .map(
      (m) => `${m.role === "user" ? "USER" : "ASSISTANT"}: ${m.content}`,
    )
    .join("\n");
}

function buildAssistantContent(state: GraphStateType): string {
  if (state.error) return `Error: ${state.error.message}`;
  const rowsPart = state.results
    ? `Returned ${state.results.count} row(s).`
    : "(no rows)";
  const sqlPart = state.sql ? `\nSQL: ${state.sql}` : "";
  return `${rowsPart}${sqlPart}`;
}

// ── Assertions ───────────────────────────────────────────────────────

function assertLiveExpectations(
  fxName: string,
  exp: LiveExpectations,
  state: GraphStateType,
): void {
  if (exp.shouldFail) {
    expect(
      state.error,
      `${fxName}: expected failure (privacy probe / clarification / validator rejection)`,
    ).toBeTruthy();
    if (exp.errorStageOneOf && state.error) {
      expect(
        exp.errorStageOneOf.includes(state.error.stage),
        `${fxName}: error stage was "${state.error.stage}"; expected one of ${exp.errorStageOneOf.join(", ")}`,
      ).toBe(true);
    }
    return;
  }

  expect(
    state.error,
    `${fxName}: unexpected error at ${state.error?.stage}: ${state.error?.message}`,
  ).toBeNull();
  expect(state.results, `${fxName}: expected non-null results`).toBeTruthy();
  if (!state.results) return;

  if (exp.minRows !== undefined) {
    expect(
      state.results.count,
      `${fxName}: expected ≥${exp.minRows} rows; got ${state.results.count}`,
    ).toBeGreaterThanOrEqual(exp.minRows);
  }
  if (exp.maxRows !== undefined) {
    expect(
      state.results.count,
      `${fxName}: expected ≤${exp.maxRows} rows; got ${state.results.count}`,
    ).toBeLessThanOrEqual(exp.maxRows);
  }
  if (exp.minColumns !== undefined) {
    expect(
      state.results.columns.length,
      `${fxName}: expected ≥${exp.minColumns} columns`,
    ).toBeGreaterThanOrEqual(exp.minColumns);
  }
  if (exp.maxColumns !== undefined) {
    expect(
      state.results.columns.length,
      `${fxName}: expected ≤${exp.maxColumns} columns`,
    ).toBeLessThanOrEqual(exp.maxColumns);
  }
  if (exp.columnsMustBeAliased) {
    for (const col of state.results.columns) {
      expect(
        looksAliased(col),
        `${fxName}: column "${col}" looks like a raw DB identifier (snake_case / all-uppercase). Aliases must be title case with spaces.`,
      ).toBe(true);
    }
  }
  if (exp.allNumericValuesInRange) {
    const { min, max } = exp.allNumericValuesInRange;
    for (const row of state.results.rows) {
      for (const col of state.results.columns) {
        const v = row[col];
        const n = numericOrNull(v);
        if (n === null) continue;
        expect(
          n,
          `${fxName}: value ${n} in column "${col}" is outside expected range [${min}, ${max}]`,
        ).toBeGreaterThanOrEqual(min);
        expect(
          n,
          `${fxName}: value ${n} in column "${col}" is outside expected range [${min}, ${max}]`,
        ).toBeLessThanOrEqual(max);
      }
    }
  }
}

function assertTurnExpectations(
  fxName: string,
  exp: TurnExpectations,
  state: GraphStateType,
  captures: Record<string, number>,
): void {
  assertLiveExpectations(fxName, exp, state);
  if (exp.sumNumericApproxEquals && !exp.shouldFail) {
    const { ref, tolerance } = exp.sumNumericApproxEquals;
    const captured = captures[ref];
    expect(
      captured,
      `${fxName}: no captured value for "${ref}". Did a previous turn use captureAs?`,
    ).toBeGreaterThan(0);
    const splitSum = sumNumericColumn(state);
    expect(splitSum, `${fxName}: no numeric column in this turn's result`).toBeGreaterThan(0);
    const ratio = splitSum / captured;
    expect(
      ratio,
      `${fxName}: sum ${splitSum} is ${(ratio * 100).toFixed(1)}% of captured ${ref} (${captured}). Tolerance ±${tolerance * 100}%. Likely INNER JOIN dropping rows or scope drift.`,
    ).toBeGreaterThanOrEqual(1 - tolerance);
    expect(
      ratio,
      `${fxName}: sum ${splitSum} exceeds captured ${ref} (${captured}) by more than ${tolerance * 100}%. Joins may be fanning out.`,
    ).toBeLessThanOrEqual(1 + tolerance);
  }
  if (exp.sqlMustContainKeywords && state.sql) {
    for (const kw of exp.sqlMustContainKeywords) {
      expect(
        state.sql.includes(kw),
        `${fxName}: generated SQL is missing required keyword "${kw}". Got:\n${state.sql}`,
      ).toBe(true);
    }
  }
}

// ── Helpers ──────────────────────────────────────────────────────────

const SNAKE_CASE = /^[a-z][a-z0-9_]*$/;
const ALL_UPPER = /^[A-Z][A-Z0-9_]*$/;

function looksAliased(col: string): boolean {
  if (SNAKE_CASE.test(col)) return false;
  if (ALL_UPPER.test(col) && col.length > 3) return false;
  return true;
}

function numericOrNull(v: unknown): number | null {
  if (typeof v === "number") return Number.isFinite(v) ? v : null;
  if (typeof v === "string") {
    const cleaned = v.replace(/,/g, "");
    if (cleaned.trim() === "") return null;
    const n = Number(cleaned);
    if (Number.isFinite(n) && /\d/.test(v) && !/[a-z]/i.test(cleaned))
      return n;
  }
  return null;
}

function extractScalarNumber(state: GraphStateType): number {
  if (!state.results || state.results.count !== 1) return 0;
  const row = state.results.rows[0];
  for (const col of state.results.columns) {
    const n = numericOrNull(row[col]);
    if (n !== null) return n;
  }
  return 0;
}

function sumNumericColumn(state: GraphStateType): number {
  if (!state.results) return 0;
  let total = 0;
  for (const row of state.results.rows) {
    for (const col of state.results.columns) {
      const n = numericOrNull(row[col]);
      if (n !== null) total += n;
    }
  }
  return total;
}

// ── Suite ────────────────────────────────────────────────────────────

const enabled = process.env.EVAL_LIVE === "1";

(enabled ? describe : describe.skip)("Live SQL-execution eval", () => {
  for (const fx of FIXTURES) {
    if (isMultiTurn(fx)) {
      it(
        fx.name,
        async () => {
          const sessionId = `eval-${randomUUID()}`;
          const history: Array<{ role: "user" | "assistant"; content: string }> = [];
          const captures: Record<string, number> = {};

          for (let i = 0; i < fx.turns.length; i++) {
            const turn = fx.turns[i];
            const conversationBlock = buildConversationBlock(history);
            const state = await runOneTurn(
              turn.question,
              sessionId,
              conversationBlock,
            );

            if (turn.expect) {
              assertTurnExpectations(
                `${fx.name} [turn ${i + 1}: "${turn.question}"]`,
                turn.expect,
                state,
                captures,
              );
            }

            if (turn.captureAs && !state.error && state.results) {
              captures[turn.captureAs] = extractScalarNumber(state);
            }

            history.push({ role: "user", content: turn.question });
            history.push({
              role: "assistant",
              content: buildAssistantContent(state),
            });
          }
        },
        180_000,
      );
    } else {
      const exp = fx.liveExpectations;
      if (!exp) continue;
      it(
        fx.name,
        async () => {
          const sessionId = `eval-${randomUUID()}`;
          const state = await runOneTurn(fx.question, sessionId, null);
          assertLiveExpectations(fx.name, exp, state);
        },
        90_000,
      );
    }
  }
});
