import mysql from "mysql2/promise";
import { env } from "@/lib/config/env";
import { SECURITY_LIMITS } from "@/lib/config/security";

/**
 * Connection pool for the external InteLIS MySQL database.
 *
 * INVARIANT: this pool is read-only. The credentials used here MUST be granted
 * SELECT only on the InteLIS database. We do not enforce read-only at the
 * driver level — operators are responsible for provisioning a read-only user
 * for `LAB_DB_USER`. SQL safety guards (SELECT-only allow-list in
 * `lib/validation/safety.ts`) provide defence in depth.
 */
declare global {
   
  var __labDbPool: mysql.Pool | undefined;
}

function createPool(): mysql.Pool {
  return mysql.createPool({
    host: env.LAB_DB_HOST,
    port: env.LAB_DB_PORT,
    database: env.LAB_DB_NAME,
    user: env.LAB_DB_USER,
    password: env.LAB_DB_PASSWORD,
    connectionLimit: 5,
    waitForConnections: true,
    queueLimit: 0,
    multipleStatements: false,
    dateStrings: true,
  });
}

export const labDb = globalThis.__labDbPool ?? createPool();

if (env.NODE_ENV !== "production") {
  globalThis.__labDbPool = labDb;
}

export interface LabQueryResult {
  columns: string[];
  rows: Record<string, unknown>[];
  count: number;
  executionMs: number;
}

export async function runLabQuery(
  sql: string,
  params: unknown[] = [],
): Promise<LabQueryResult> {
  const start = performance.now();
  const [rows, fields] = await labDb.query({
    sql,
    values: params,
    timeout: SECURITY_LIMITS.labQueryTimeoutMs,
  });
  const executionMs = Math.round(performance.now() - start);

  if (!Array.isArray(rows)) {
    return { columns: [], rows: [], count: 0, executionMs };
  }

  const columns = fields?.map((f) => f.name) ?? [];
  const typedRows = rows as Record<string, unknown>[];
  return {
    columns,
    rows: typedRows,
    count: typedRows.length,
    executionMs,
  };
}
