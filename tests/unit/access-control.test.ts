import { describe, expect, it } from "vitest";
import { enforceAccess } from "@/lib/validation/access-control";
import type { UserContext } from "@/lib/auth/rbac";

const national: UserContext = {
  userId: "u",
  accessLevel: "national",
  allowedProvinces: [],
  allowedDistricts: [],
};

const province: UserContext = {
  userId: "u",
  accessLevel: "province",
  allowedProvinces: ["10", "11"],
  allowedDistricts: [],
};

const district: UserContext = {
  userId: "u",
  accessLevel: "district",
  allowedProvinces: [],
  allowedDistricts: ["101"],
};

describe("enforceAccess — national users", () => {
  it("passes SQL through unchanged", () => {
    const sql = "SELECT COUNT(*) FROM form_vl";
    const result = enforceAccess(sql, national);
    expect(result.allowed).toBe(true);
    expect(result.rewrittenSql).toBe(sql);
  });
});

describe("enforceAccess — province scope", () => {
  it("injects facility_state_id IN (…) on a facility-joined query", () => {
    const sql =
      "SELECT fd.facility_name, COUNT(*) FROM form_vl fv JOIN facility_details fd ON fv.lab_id = fd.facility_id GROUP BY fd.facility_name";
    const result = enforceAccess(sql, province);
    expect(result.allowed).toBe(true);
    expect(result.rewrittenSql).toMatch(/facility_state_id/);
    expect(result.rewrittenSql).toMatch(/'10'/);
    expect(result.rewrittenSql).toMatch(/'11'/);
  });

  it("rejects when query has no facility_details reference", () => {
    const result = enforceAccess(
      "SELECT COUNT(*) FROM form_vl",
      province,
    );
    expect(result.allowed).toBe(false);
    expect(result.reason).toMatch(/facility_details/);
  });

  it("rejects when allowed list is empty", () => {
    const result = enforceAccess(
      "SELECT COUNT(*) FROM facility_details",
      { ...province, allowedProvinces: [] },
    );
    expect(result.allowed).toBe(false);
  });

  it("preserves existing WHERE by AND-ing the scope clause", () => {
    const sql =
      "SELECT COUNT(*) FROM facility_details fd WHERE fd.is_active = 'yes'";
    const result = enforceAccess(sql, province);
    expect(result.allowed).toBe(true);
    expect(result.rewrittenSql).toMatch(/is_active/);
    expect(result.rewrittenSql).toMatch(/facility_state_id/);
  });
});

describe("enforceAccess — district scope", () => {
  it("injects facility_district_id IN (…)", () => {
    const sql =
      "SELECT COUNT(*) FROM form_vl fv JOIN facility_details fd ON fv.lab_id = fd.facility_id";
    const result = enforceAccess(sql, district);
    expect(result.allowed).toBe(true);
    expect(result.rewrittenSql).toMatch(/facility_district_id/);
    expect(result.rewrittenSql).toMatch(/'101'/);
  });
});

describe("enforceAccess — structural rejections", () => {
  it("rejects unparseable SQL", () => {
    expect(enforceAccess("this is not sql", province).allowed).toBe(false);
  });

  it("rejects CTEs for scoped users", () => {
    const sql =
      "WITH x AS (SELECT * FROM form_vl) SELECT * FROM x JOIN facility_details ON 1=1";
    expect(enforceAccess(sql, province).allowed).toBe(false);
  });
});
