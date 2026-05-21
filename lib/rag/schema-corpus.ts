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

  // Append explicit relationships so the LLM doesn't invent join columns.
  // Includes FK-declared relationships from information_schema plus the
  // canonical InteLIS lab joins (form_*.lab_id -> facility_details.facility_id
  // and facility_details.facility_state_id / facility_district_id ->
  // geographical_divisions.geo_id) even when the FK isn't declared.
  const allowed = new Set(tables);
  const rels = collectRelationships(doc, tables);
  const relevant = rels.filter(
    (r) => allowed.has(r.from_table) && allowed.has(r.to_table),
  );
  if (relevant.length > 0) {
    parts.push("");
    parts.push("RELATIONSHIPS (use these JOIN paths; never invent columns):");
    for (const r of relevant) {
      parts.push(
        `  ${r.from_table}.${r.from_column} -> ${r.to_table}.${r.to_column}`,
      );
    }
  }
  return parts.join("\n");
}

function collectRelationships(
  doc: SchemaDoc,
  tables: readonly string[],
): SchemaRelationship[] {
  const allowed = new Set(tables);
  const map = new Map<string, SchemaRelationship>();
  const add = (r: SchemaRelationship) => {
    const key = `${r.from_table}.${r.from_column}->${r.to_table}.${r.to_column}`;
    if (!map.has(key)) map.set(key, r);
  };

  for (const r of doc.relationships) add(r);

  // Canonical lab joins — facility_details bridges every form_* table.
  const FORM_TABLES = [
    "form_vl",
    "form_eid",
    "form_covid19",
    "form_tb",
    "form_hepatitis",
    "form_cd4",
    "form_generic",
  ];
  if (allowed.has("facility_details")) {
    for (const ft of FORM_TABLES) {
      if (!allowed.has(ft)) continue;
      const formTable = doc.tables[ft];
      if (!formTable) continue;
      if (formTable.columns.some((c) => c.name === "lab_id")) {
        add({
          from_table: ft,
          from_column: "lab_id",
          to_table: "facility_details",
          to_column: "facility_id",
        });
      }
      if (formTable.columns.some((c) => c.name === "facility_id")) {
        add({
          from_table: ft,
          from_column: "facility_id",
          to_table: "facility_details",
          to_column: "facility_id",
        });
      }
    }
    if (allowed.has("geographical_divisions")) {
      add({
        from_table: "facility_details",
        from_column: "facility_state_id",
        to_table: "geographical_divisions",
        to_column: "geo_id",
      });
      add({
        from_table: "facility_details",
        from_column: "facility_district_id",
        to_table: "geographical_divisions",
        to_column: "geo_id",
      });
    }
  }
  return Array.from(map.values());
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
