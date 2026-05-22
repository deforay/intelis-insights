import { describe, expect, it, vi } from "vitest";
import { executeQuery } from "@/lib/graph/nodes/execute-query";
import { validateQuery } from "@/lib/graph/nodes/validate-query";
import type { GraphStateType } from "@/lib/graph/state";

vi.mock("@/lib/db/lab", () => ({
  runLabQuery: vi.fn(async () => {
    throw new Error("ER_BAD_FIELD_ERROR: Unknown column 'secret_column'");
  }),
}));

function makeState(patch: Partial<GraphStateType>): GraphStateType {
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
    narration: null,
    followUpSuggestions: null,
    chart: null,
    error: null,
    sqlRetries: 0,
    traceId: "t",
    startedAt: 0,
    ...patch,
  };
}

describe("graph error sanitization", () => {
  it("keeps DB details internal while returning a generic message", async () => {
    const result = await executeQuery(
      makeState({ sql: "SELECT COUNT(*) AS `Tests` FROM form_vl" }),
    );

    expect(result.error?.code).toBe("db_error");
    expect(result.error?.message).toBe(
      "The query could not be executed safely. Please refine your question and try again.",
    );
    expect(result.error?.message).not.toContain("secret_column");
    expect(result.error?.internalMessage).toContain("secret_column");
  });

  it("keeps validator details internal while returning a generic message", async () => {
    const result = await validateQuery(
      makeState({ sql: "SELECT * FROM form_vl" }),
    );

    expect(result.error?.code).toBe("wildcard_select");
    expect(result.error?.message).toBe(
      "The generated SQL did not pass safety validation. Please refine your question and try again.",
    );
    expect(result.error?.message).not.toContain("wildcard");
    expect(result.error?.internalMessage).toContain("wildcard");
  });
});
