/**
 * Live SQL-execution eval harness.
 *
 * Runs each fixture's NL question through the full LangGraph
 * pipeline against the real Qdrant + LLM + lab MySQL stack and
 * asserts the result actually executed and returned a sensible
 * shape. This is the harness that would have caught:
 *   - The "Month LIMIT 10000" syntax error (bare reserved-word alias).
 *   - The INNER JOIN row-drop (province split 405+405 ≠ 9990 total).
 *   - Any SQL the validator passed but MySQL refused.
 *
 * Gated behind EVAL_LIVE=1 so `npm test` and the cheaper
 * `npm run eval` (SQL-only feature-shape harness) don't accidentally
 * hit the live lab DB.
 *
 * Usage:
 *   docker compose up -d postgres qdrant     # the graph needs them
 *   npm run eval:live                         # against $LAB_DB_*
 *   npm run eval:live -- -t vl-by-province    # subset
 *
 * Requires real env (no SKIP_ENV_VALIDATION). The fixtures are run
 * one at a time so failures don't pollute each other's state.
 */
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { randomUUID } from "node:crypto";
import { runQuery } from "@/lib/graph/runner";
import type { GraphStateType } from "@/lib/graph/state";
import type { QueryEvent } from "@/lib/graph/events";
import type { UserContext } from "@/lib/auth/rbac";

interface LiveExpectations {
  shouldFail?: boolean;
  errorStageOneOf?: string[];
  minRows?: number;
  maxRows?: number;
  minColumns?: number;
  maxColumns?: number;
}

interface Fixture {
  name: string;
  question: string;
  liveExpectations?: LiveExpectations;
}

const FIXTURES: Fixture[] = JSON.parse(
  fs.readFileSync(
    path.join(__dirname, "fixtures", "queries.json"),
    "utf-8",
  ),
);

const NATIONAL_USER: UserContext = {
  userId: "eval-runner",
  accessLevel: "national",
  allowedProvinces: [],
  allowedDistricts: [],
};

const enabled = process.env.EVAL_LIVE === "1";

async function runFixture(question: string): Promise<GraphStateType> {
  const sessionId = `eval-${randomUUID()}`;
  const { events, final } = await runQuery({
    question,
    sessionId,
    userContext: NATIONAL_USER,
    conversationBlock: null,
  });

  // Drain the event stream — we only care about the final state.
  // Track errors as they stream so a runtime error reaches the test.
  const errors: QueryEvent[] = [];
  for await (const ev of events) {
    if (ev.type === "error") errors.push(ev);
  }

  const state = await final;
  if (state.error || errors.length > 0) {
    // The state.error is set if any node yielded one; this keeps it.
    return state;
  }
  return state;
}

(enabled ? describe : describe.skip)(
  "Live SQL-execution eval",
  () => {
    for (const fx of FIXTURES) {
      const exp = fx.liveExpectations;
      if (!exp) continue;
      it(
        fx.name,
        async () => {
          const state = await runFixture(fx.question);

          if (exp.shouldFail) {
            expect(
              state.error,
              `expected ${fx.name} to fail (privacy probe, validator rejection, or clarification)`,
            ).toBeTruthy();
            if (exp.errorStageOneOf && state.error) {
              expect(
                exp.errorStageOneOf.includes(state.error.stage),
                `error stage was "${state.error.stage}"; expected one of ${exp.errorStageOneOf.join(", ")}`,
              ).toBe(true);
            }
            return;
          }

          // Success path
          expect(
            state.error,
            `unexpected error at ${state.error?.stage}: ${state.error?.message}`,
          ).toBeNull();
          expect(state.results, "expected non-null results").toBeTruthy();
          if (!state.results) return;

          if (exp.minRows !== undefined) {
            expect(
              state.results.count,
              `expected ≥${exp.minRows} rows; got ${state.results.count}`,
            ).toBeGreaterThanOrEqual(exp.minRows);
          }
          if (exp.maxRows !== undefined) {
            expect(
              state.results.count,
              `expected ≤${exp.maxRows} rows; got ${state.results.count}`,
            ).toBeLessThanOrEqual(exp.maxRows);
          }
          if (exp.minColumns !== undefined) {
            expect(state.results.columns.length).toBeGreaterThanOrEqual(
              exp.minColumns,
            );
          }
          if (exp.maxColumns !== undefined) {
            expect(state.results.columns.length).toBeLessThanOrEqual(
              exp.maxColumns,
            );
          }
        },
        90_000,
      );
    }

    /**
     * Row-drop invariant: when we ask for a total and then for a
     * breakdown of the same total, the breakdown's rows must sum to
     * within 5% of the total. This is the test that would have flagged
     * the INNER JOIN drop (province split 810 ≠ 9990 total).
     */
    it("vl-total ≈ sum(vl-by-province)", async () => {
      const totalState = await runFixture(
        "How many VL tests were done last month?",
      );
      const splitState = await runFixture(
        "How many VL tests last month by province?",
      );

      expect(totalState.error).toBeNull();
      expect(splitState.error).toBeNull();
      const total = numericFromKpiRow(totalState);
      const splitSum = sumNumericColumn(splitState);

      expect(total, "no numeric value in unbroken total").toBeGreaterThan(0);
      expect(splitSum, "no numeric column in split").toBeGreaterThan(0);

      const ratio = splitSum / total;
      expect(
        ratio,
        `split sum (${splitSum}) is only ${(ratio * 100).toFixed(1)}% of unbroken total (${total}) — INNER JOIN likely dropping rows`,
      ).toBeGreaterThanOrEqual(0.95);
      expect(
        ratio,
        `split sum (${splitSum}) exceeds unbroken total (${total}) — joins may be fanning out`,
      ).toBeLessThanOrEqual(1.05);
    }, 180_000);
  },
);

function numericFromKpiRow(state: GraphStateType): number {
  if (!state.results || state.results.count !== 1) return 0;
  const row = state.results.rows[0];
  for (const col of state.results.columns) {
    const v = row[col];
    if (typeof v === "number") return v;
    if (typeof v === "string") {
      const n = Number(v.replace(/,/g, ""));
      if (Number.isFinite(n)) return n;
    }
  }
  return 0;
}

function sumNumericColumn(state: GraphStateType): number {
  if (!state.results) return 0;
  // Sum all numeric columns over all rows. For breakdowns this picks
  // up the count column regardless of its name.
  let total = 0;
  for (const row of state.results.rows) {
    for (const col of state.results.columns) {
      const v = row[col];
      if (typeof v === "number") {
        total += v;
        continue;
      }
      if (typeof v === "string") {
        const n = Number(v.replace(/,/g, ""));
        if (Number.isFinite(n) && /\d/.test(v) && !/[a-z]/i.test(v)) {
          total += n;
        }
      }
    }
  }
  return total;
}
