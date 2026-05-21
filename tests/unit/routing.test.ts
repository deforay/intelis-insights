import { describe, expect, it } from "vitest";
import {
  afterGenerateSql,
  afterValidateAccess,
  afterValidateQuery,
} from "@/lib/graph/routing";
import type { GraphStateType } from "@/lib/graph/state";

function makeState(overrides: Partial<GraphStateType>): GraphStateType {
  return {
    question: "q",
    sessionId: "s",
    userContext: {
      userId: "u",
      accessLevel: "national",
      allowedProvinces: [],
      allowedDistricts: [],
    },
    conversationBlock: null,
    intent: null,
    ragContext: null,
    sql: null,
    sqlMeta: null,
    accessDecision: null,
    results: null,
    chart: null,
    error: null,
    sqlRetries: 0,
    traceId: "t",
    startedAt: 0,
    ...overrides,
  };
}

describe("afterGenerateSql", () => {
  it("routes to validate-access on success", () => {
    expect(afterGenerateSql(makeState({ sql: "SELECT 1" }))).toBe(
      "validate-access",
    );
  });
  it("routes to format-response on error", () => {
    expect(
      afterGenerateSql(
        makeState({
          sql: null,
          error: {
            code: "empty_sql",
            message: "x",
            stage: "generate-sql",
          },
        }),
      ),
    ).toBe("format-response");
  });
  it("routes to format-response when sql is missing", () => {
    expect(afterGenerateSql(makeState({ sql: null }))).toBe("format-response");
  });
});

describe("afterValidateAccess", () => {
  it("routes to validate-query when access is allowed", () => {
    expect(
      afterValidateAccess(
        makeState({
          accessDecision: {
            allowed: true,
            rewrittenSql: "SELECT 1",
            reason: "ok",
          },
        }),
      ),
    ).toBe("validate-query");
  });
  it("routes to format-response when access is denied", () => {
    expect(
      afterValidateAccess(
        makeState({
          accessDecision: {
            allowed: false,
            rewrittenSql: null,
            reason: "out of scope",
          },
          error: {
            code: "access_denied",
            message: "out of scope",
            stage: "validate-access",
          },
        }),
      ),
    ).toBe("format-response");
  });
});

describe("afterValidateQuery", () => {
  it("routes to execute-query on success", () => {
    expect(afterValidateQuery(makeState({ error: null }))).toBe(
      "execute-query",
    );
  });
  it("retries generate-sql once on validation failure", () => {
    expect(
      afterValidateQuery(
        makeState({
          error: {
            code: "privacy_violation",
            message: "x",
            stage: "validate-query",
          },
          sqlRetries: 0,
        }),
      ),
    ).toBe("generate-sql");
  });
  it("gives up after the first retry", () => {
    expect(
      afterValidateQuery(
        makeState({
          error: {
            code: "privacy_violation",
            message: "x",
            stage: "validate-query",
          },
          sqlRetries: 1,
        }),
      ),
    ).toBe("format-response");
  });
});
