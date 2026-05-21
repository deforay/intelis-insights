#!/usr/bin/env tsx
/**
 * Export the InteLIS MySQL schema to `corpus/schema.json`.
 *
 * Reads `information_schema` from the lab DB (read-only) and emits a
 * structured document the RAG corpus builder consumes. The output shape
 * matches the retired project's `var/schema.json` (version 2.0).
 *
 *   tables[]:         every base table with column metadata
 *   relationships[]:  foreign keys (REFERENCED_TABLE_NAME from KCU)
 *   reference_data{}: sample rows from r_* lookup tables (small)
 *
 * Usage:
 *   npm run schema:export
 *   # or directly:
 *   tsx scripts/export-schema.ts
 *
 * Required env: LAB_DB_HOST, LAB_DB_PORT, LAB_DB_NAME, LAB_DB_USER,
 * LAB_DB_PASSWORD (read-only credentials are sufficient).
 */
import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import mysql from "mysql2/promise";

interface Column {
  name: string;
  type: string;
  nullable: boolean;
  key: string | null;
  default: string | null;
  comment: string;
}

interface Table {
  name: string;
  type: "base table" | "view";
  columns: Column[];
}

interface Relationship {
  from_table: string;
  from_column: string;
  to_table: string;
  to_column: string;
}

interface ReferenceData {
  total_rows: number;
  sample_rows: number;
  data: Record<string, unknown>[];
}

interface SchemaDoc {
  version: "2.0";
  database: string;
  exported_at: string;
  tables: Record<string, Table>;
  relationships: Relationship[];
  reference_data: Record<string, ReferenceData>;
}

const REFERENCE_TABLE_MAX_ROWS = 100;
const REFERENCE_SAMPLE_LIMIT = 25;

async function main() {
  const host = required("LAB_DB_HOST");
  const port = Number(process.env.LAB_DB_PORT ?? 3306);
  const database = required("LAB_DB_NAME");
  const user = required("LAB_DB_USER");
  const password = process.env.LAB_DB_PASSWORD ?? "";

  console.log(`Connecting to ${user}@${host}:${port}/${database} (read-only)`);
  const conn = await mysql.createConnection({
    host,
    port,
    database,
    user,
    password,
    multipleStatements: false,
    dateStrings: true,
  });

  try {
    const tables = await fetchTables(conn, database);
    console.log(`Found ${Object.keys(tables).length} base tables`);

    const relationships = await fetchRelationships(conn, database);
    console.log(`Found ${relationships.length} foreign-key relationships`);

    const refTables = Object.values(tables).filter((t) =>
      t.name.startsWith("r_"),
    );
    console.log(
      `Sampling reference data from ${refTables.length} r_* tables…`,
    );
    const reference_data = await fetchReferenceData(conn, refTables);

    const doc: SchemaDoc = {
      version: "2.0",
      database,
      exported_at: new Date().toISOString(),
      tables,
      relationships,
      reference_data,
    };

    const out = path.resolve("corpus/schema.json");
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, JSON.stringify(doc, null, 2));
    console.log(`Wrote ${out} (${humanSize(out)})`);
  } finally {
    await conn.end();
  }
}

function required(name: string): string {
  const v = process.env[name];
  if (!v) {
    throw new Error(`Missing required env: ${name}`);
  }
  return v;
}

interface InfoColumnRow {
  TABLE_NAME: string;
  COLUMN_NAME: string;
  COLUMN_TYPE: string;
  IS_NULLABLE: "YES" | "NO";
  COLUMN_KEY: string;
  COLUMN_DEFAULT: string | null;
  COLUMN_COMMENT: string;
  ORDINAL_POSITION: number;
}

interface InfoTableRow {
  TABLE_NAME: string;
  TABLE_TYPE: string;
}

async function fetchTables(
  conn: mysql.Connection,
  database: string,
): Promise<Record<string, Table>> {
  const [tableRowsRaw] = await conn.query<mysql.RowDataPacket[]>(
    `SELECT TABLE_NAME, TABLE_TYPE
     FROM information_schema.tables
     WHERE TABLE_SCHEMA = ?
       AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME`,
    [database],
  );
  const tableRows = tableRowsRaw as unknown as InfoTableRow[];

  const [colRowsRaw] = await conn.query<mysql.RowDataPacket[]>(
    `SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
            COLUMN_KEY, COLUMN_DEFAULT, COLUMN_COMMENT, ORDINAL_POSITION
     FROM information_schema.columns
     WHERE TABLE_SCHEMA = ?
     ORDER BY TABLE_NAME, ORDINAL_POSITION`,
    [database],
  );
  const colRows = colRowsRaw as unknown as InfoColumnRow[];

  const columnsByTable = new Map<string, Column[]>();
  for (const c of colRows) {
    const list = columnsByTable.get(c.TABLE_NAME) ?? [];
    list.push({
      name: c.COLUMN_NAME,
      type: c.COLUMN_TYPE,
      nullable: c.IS_NULLABLE === "YES",
      key: c.COLUMN_KEY === "" ? null : c.COLUMN_KEY,
      default: c.COLUMN_DEFAULT,
      comment: c.COLUMN_COMMENT,
    });
    columnsByTable.set(c.TABLE_NAME, list);
  }

  const out: Record<string, Table> = {};
  for (const t of tableRows) {
    out[t.TABLE_NAME] = {
      name: t.TABLE_NAME,
      type: "base table",
      columns: columnsByTable.get(t.TABLE_NAME) ?? [],
    };
  }
  return out;
}

interface InfoKcuRow {
  TABLE_NAME: string;
  COLUMN_NAME: string;
  REFERENCED_TABLE_NAME: string | null;
  REFERENCED_COLUMN_NAME: string | null;
}

async function fetchRelationships(
  conn: mysql.Connection,
  database: string,
): Promise<Relationship[]> {
  const [rowsRaw] = await conn.query<mysql.RowDataPacket[]>(
    `SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.key_column_usage
     WHERE TABLE_SCHEMA = ?
       AND REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY TABLE_NAME, COLUMN_NAME`,
    [database],
  );
  const rows = rowsRaw as unknown as InfoKcuRow[];

  return rows
    .filter(
      (r): r is InfoKcuRow & { REFERENCED_TABLE_NAME: string; REFERENCED_COLUMN_NAME: string } =>
        !!r.REFERENCED_TABLE_NAME && !!r.REFERENCED_COLUMN_NAME,
    )
    .map((r) => ({
      from_table: r.TABLE_NAME,
      from_column: r.COLUMN_NAME,
      to_table: r.REFERENCED_TABLE_NAME,
      to_column: r.REFERENCED_COLUMN_NAME,
    }));
}

async function fetchReferenceData(
  conn: mysql.Connection,
  tables: Table[],
): Promise<Record<string, ReferenceData>> {
  const out: Record<string, ReferenceData> = {};
  for (const t of tables) {
    try {
      const [[countRow]] = (await conn.query(
        `SELECT COUNT(*) AS c FROM \`${t.name}\``,
      )) as unknown as [[{ c: number }]];
      const total = Number(countRow.c);
      if (total > REFERENCE_TABLE_MAX_ROWS) continue;

      const [rowsRaw] = await conn.query<mysql.RowDataPacket[]>(
        `SELECT * FROM \`${t.name}\` LIMIT ?`,
        [REFERENCE_SAMPLE_LIMIT],
      );
      out[t.name] = {
        total_rows: total,
        sample_rows: rowsRaw.length,
        data: rowsRaw as unknown as Record<string, unknown>[],
      };
    } catch (err) {
      console.warn(
        `Skipping reference table ${t.name}: ${(err as Error).message}`,
      );
    }
  }
  return out;
}

function humanSize(filePath: string): string {
  const bytes = fs.statSync(filePath).size;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
