/**
 * Lazy loader for the exported InteLIS schema document.
 *
 * `corpus/schema.json` is produced by `npm run schema:export`. We read it
 * once per process and reuse it for prompt assembly. The file is shipped
 * with the Docker image — re-run `schema:export && rag:build && rag:upsert`
 * whenever the source MySQL schema changes.
 */
import fs from "node:fs";
import path from "node:path";

export interface SchemaColumn {
  name: string;
  type: string;
  nullable: boolean;
  key: string | null;
  default: string | null;
  comment: string;
}

export interface SchemaTable {
  name: string;
  type: "base table" | "view";
  columns: SchemaColumn[];
}

export interface SchemaRelationship {
  from_table: string;
  from_column: string;
  to_table: string;
  to_column: string;
}

export interface SchemaDoc {
  version: "2.0";
  database: string;
  exported_at: string;
  tables: Record<string, SchemaTable>;
  relationships: SchemaRelationship[];
  reference_data: Record<string, unknown>;
}

const SCHEMA_PATH = path.resolve(process.cwd(), "corpus/schema.json");

let cached: SchemaDoc | null = null;

export function loadSchema(): SchemaDoc {
  if (cached) return cached;
  if (!fs.existsSync(SCHEMA_PATH)) {
    throw new Error(
      `corpus/schema.json not found at ${SCHEMA_PATH}. ` +
        `Run \`npm run schema:export\` against the InteLIS MySQL DB first.`,
    );
  }
  const raw = fs.readFileSync(SCHEMA_PATH, "utf-8");
  const parsed = JSON.parse(raw) as SchemaDoc;
  cached = parsed;
  return parsed;
}

export function buildSchemaBlock(tables: readonly string[]): string {
  const doc = loadSchema();
  const parts: string[] = [];
  for (const tbl of tables) {
    const info = doc.tables[tbl];
    if (!info) continue;
    const columns = info.columns.map((c) => c.name).join(", ");
    parts.push(`${tbl}: ${columns}`);
  }
  return parts.join("\n");
}

export function relationshipsForTables(
  tables: readonly string[],
): SchemaRelationship[] {
  const doc = loadSchema();
  const allowed = new Set(tables);
  return doc.relationships.filter(
    (r) => allowed.has(r.from_table) || allowed.has(r.to_table),
  );
}
