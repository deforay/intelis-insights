import { describe, expect, it } from "vitest";
import { SCOPE_LIMITS } from "@/lib/config/business-rules";
import { clampResultLimit } from "@/lib/validation/query-limit";

describe("clampResultLimit", () => {
  it("adds the hard result limit when SQL has no LIMIT", () => {
    expect(clampResultLimit("SELECT COUNT(*) AS `Tests` FROM form_vl")).toBe(
      `SELECT COUNT(*) AS \`Tests\` FROM form_vl LIMIT ${SCOPE_LIMITS.maxResultLimit}`,
    );
  });

  it("keeps an existing limit below the maximum", () => {
    expect(
      clampResultLimit("SELECT sample_code AS `Sample Code` FROM form_vl LIMIT 50"),
    ).toContain("LIMIT 50");
  });

  it("clamps an excessive simple LIMIT", () => {
    expect(
      clampResultLimit(
        "SELECT COUNT(*) AS `Tests` FROM form_vl LIMIT 999999999",
      ),
    ).toContain(`LIMIT ${SCOPE_LIMITS.maxResultLimit}`);
  });

  it("clamps the row-count operand for LIMIT offset, count", () => {
    expect(
      clampResultLimit(
        "SELECT sample_code AS `Sample Code` FROM form_vl LIMIT 5, 999999999",
      ),
    ).toContain(`LIMIT 5, ${SCOPE_LIMITS.maxResultLimit}`);
  });
});
