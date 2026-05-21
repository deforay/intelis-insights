import { describe, expect, it } from "vitest";
import {
  SqlValidationError,
  validateSql,
} from "@/lib/validation/safety";

describe("validateSql — happy path", () => {
  it("accepts a simple SELECT on an allowlisted table", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) FROM form_vl"),
    ).not.toThrow();
  });

  it("accepts a JOIN across allowlisted tables", () => {
    expect(() =>
      validateSql(
        "SELECT fd.facility_name, COUNT(*) FROM form_vl fv JOIN facility_details fd ON fv.lab_id = fd.facility_id GROUP BY fd.facility_name",
      ),
    ).not.toThrow();
  });

  it("accepts COUNT(DISTINCT patient_id) under the privacy carve-out", () => {
    expect(() =>
      validateSql(
        "SELECT COUNT(DISTINCT patient_id) AS unique_patients FROM form_vl",
      ),
    ).not.toThrow();
  });
});

describe("validateSql — rejections", () => {
  it("rejects non-SELECT statements", () => {
    expect(() => validateSql("DELETE FROM form_vl")).toThrow(SqlValidationError);
  });

  it("rejects disallowed tables", () => {
    expect(() => validateSql("SELECT * FROM secret_table")).toThrow(
      /allowlist/,
    );
  });

  it("rejects raw patient_first_name in SELECT", () => {
    try {
      validateSql("SELECT patient_first_name FROM form_vl");
      throw new Error("expected validateSql to throw");
    } catch (err) {
      expect(err).toBeInstanceOf(SqlValidationError);
      expect((err as SqlValidationError).code).toBe("privacy_violation");
    }
  });

  it("rejects SELECT patient_id (not inside COUNT(DISTINCT …))", () => {
    try {
      validateSql("SELECT patient_id FROM form_vl");
      throw new Error("expected validateSql to throw");
    } catch (err) {
      expect(err).toBeInstanceOf(SqlValidationError);
      expect((err as SqlValidationError).code).toBe("privacy_violation");
    }
  });

  it("ignores forbidden tokens inside string literals", () => {
    expect(() =>
      validateSql(
        "SELECT facility_name FROM facility_details WHERE facility_name = 'patient_first_name'",
      ),
    ).not.toThrow();
  });

  it("rejects DROP / TRUNCATE patterns", () => {
    expect(() =>
      validateSql("SELECT * FROM form_vl; DROP TABLE form_vl"),
    ).toThrow();
  });
});
