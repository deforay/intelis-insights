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

  it("accepts grouped aggregate breakdowns", () => {
    expect(() =>
      validateSql(
        "SELECT fd.facility_name AS `Facility`, COUNT(*) AS `Tests` FROM form_vl fv JOIN facility_details fd ON fv.lab_id = fd.facility_id GROUP BY fd.facility_name",
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

  it("rejects SELECT * wildcard projections", () => {
    expect(() => validateSql("SELECT * FROM form_vl")).toThrow(
      /wildcard projections/,
    );
  });

  it("rejects table.* wildcard projections", () => {
    expect(() => validateSql("SELECT fv.* FROM form_vl fv")).toThrow(
      /wildcard projections/,
    );
  });

  it("rejects aggregate queries with ungrouped raw columns", () => {
    expect(() =>
      validateSql(
        "SELECT COUNT(*) AS `Tests`, sample_code AS `Sample Code` FROM form_vl",
      ),
    ).toThrow(/raw columns/);
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

  it("rejects forbidden columns inside functions unless explicitly allowed", () => {
    expect(() =>
      validateSql("SELECT COUNT(patient_id) AS `Patients` FROM form_vl"),
    ).toThrow(/forbidden patient-identifier/);
    expect(() =>
      validateSql("SELECT LOWER(patient_first_name) AS `Name` FROM form_vl"),
    ).toThrow(/forbidden patient-identifier/);
  });

  it("rejects forbidden column names used as aliases", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) AS `patient_id` FROM form_vl"),
    ).toThrow(/forbidden patient-identifier/);
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

  it("rejects SELECT INTO OUTFILE", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) AS `Tests` FROM form_vl INTO OUTFILE '/tmp/leak.csv'"),
    ).toThrow(/SELECT \.\.\. INTO/);
  });

  it("rejects file and delay functions", () => {
    expect(() =>
      validateSql("SELECT LOAD_FILE('/etc/passwd') AS `File` FROM form_vl"),
    ).toThrow(/LOAD_FILE/);
    expect(() =>
      validateSql("SELECT SLEEP(30) AS `Delay` FROM form_vl"),
    ).toThrow(/SLEEP/);
    expect(() =>
      validateSql("SELECT BENCHMARK(1000000, MD5('x')) AS `Bench` FROM form_vl"),
    ).toThrow(/BENCHMARK/);
  });

  it("rejects excessive LIMIT clauses", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) AS `Tests` FROM form_vl LIMIT 999999999"),
    ).toThrow(/LIMIT must not exceed/);
  });

  it("rejects SQL comments", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) AS `Tests` FROM form_vl /* hidden */"),
    ).toThrow(/comments/);
  });

  it("rejects CTEs", () => {
    expect(() =>
      validateSql(
        "WITH x AS (SELECT COUNT(*) AS c FROM form_vl) SELECT c AS `Tests` FROM x",
      ),
    ).toThrow(SqlValidationError);
  });

  it("rejects subqueries", () => {
    expect(() =>
      validateSql(
        "SELECT COUNT(*) AS `Tests` FROM (SELECT sample_code FROM form_vl) x",
      ),
    ).toThrow(/subqueries/);
  });

  it("rejects UNION queries", () => {
    expect(() =>
      validateSql(
        "SELECT COUNT(*) AS `Tests` FROM form_vl UNION SELECT COUNT(*) AS `Tests` FROM form_eid",
      ),
    ).toThrow(SqlValidationError);
  });

  it("rejects schema-qualified table names", () => {
    expect(() =>
      validateSql("SELECT COUNT(*) AS `Tests` FROM intelis.form_vl"),
    ).toThrow(/schema-qualified/);
  });
});
