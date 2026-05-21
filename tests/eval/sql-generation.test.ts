/**
 * SQL-generation eval harness.
 *
 * Runs each NL question in fixtures/queries.json through the LLM's
 * SQL-gen path and asserts the produced SQL has the expected shape.
 * Gated behind EVAL=1 so the default `npm test` doesn't hit a live
 * LLM API.
 *
 * Usage:
 *   npm run eval                            # all fixtures
 *   EVAL=1 npx vitest run tests/eval -t vl  # subset
 *
 * Requires the same env as the app:
 *   OPENAI_API_KEY (or another configured LLM_PROVIDER) plus
 *   QDRANT_URL pointing at a populated corpus.
 *
 * This harness validates structural features of the generated SQL.
 * It does NOT execute the SQL against the lab DB — that would catch
 * row-count regressions (e.g. INNER JOIN dropping rows) but needs a
 * stable test fixture for the InteLIS data. That's a v1.x harness.
 */
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { generateStructured } from "@/lib/llm/structured";
import { z } from "zod";
import {
  SQL_GENERATION_SYSTEM,
  sqlGenerationUserPrompt,
} from "@/lib/llm/prompts";
import {
  buildSchemaBlock,
  loadSchema,
} from "@/lib/rag/schema-corpus";
import {
  searchIntentFacts,
  searchTableContext,
} from "@/lib/rag/search";
import { extractFeatures } from "./sql-features";

interface Fixture {
  name: string;
  question: string;
  tables: string[];
  expectations: {
    mustReferenceTables?: string[];
    mustNotReference?: string[];
    mustContainKeywords?: string[];
    mustNotContainKeywords?: string[];
    mustHaveAliases?: boolean;
    mustBeSelectOnly?: boolean;
    shouldClarifyOrReject?: boolean;
  };
}

const SqlOutputSchema = z.object({
  sql: z.string(),
  confidence: z.number().min(0).max(1),
  assumptions: z.array(z.string()),
  citations: z.array(z.string()),
  clarificationNeeded: z
    .object({ question: z.string(), reason: z.string() })
    .nullable(),
});

const FIXTURES: Fixture[] = JSON.parse(
  fs.readFileSync(
    path.join(__dirname, "fixtures", "queries.json"),
    "utf-8",
  ),
);

const enabled = process.env.EVAL === "1";

(enabled ? describe : describe.skip)(
  "SQL generation eval (live LLM)",
  () => {
    // Sanity check that the corpus is loadable.
    it("can load corpus/schema.json", () => {
      const schema = loadSchema();
      expect(schema.tables).toBeDefined();
    });

    for (const fx of FIXTURES) {
      it(
        fx.name,
        async () => {
          const [intentHits, tableHits] = await Promise.all([
            searchIntentFacts(fx.question, 14),
            searchTableContext(fx.question, fx.tables, 15),
          ]);
          const pack = [...intentHits, ...tableHits].map((h) => ({
            id: h.id,
            t: h.type,
            x: h.text,
          }));
          const ragJson = JSON.stringify(pack);
          const schemaBlock = buildSchemaBlock(fx.tables);

          const { object } = await generateStructured({
            schema: SqlOutputSchema,
            schemaName: "sql_generation",
            system: SQL_GENERATION_SYSTEM,
            prompt: sqlGenerationUserPrompt({
              schemaBlock,
              ragJson,
              conversationBlock: null,
              question: fx.question,
            }),
            modelKind: "primary",
            temperature: 0,
            maxOutputTokens: 1500,
          });

          if (fx.expectations.shouldClarifyOrReject) {
            const refused = !object.sql.trim() || !!object.clarificationNeeded;
            expect(refused, "expected clarification or refusal").toBe(true);
            return;
          }

          expect(object.sql.trim(), "non-empty SQL").not.toBe("");
          const features = extractFeatures(object.sql);

          if (fx.expectations.mustBeSelectOnly) {
            expect(features.isSelectOnly, "SELECT-only").toBe(true);
          }
          if (fx.expectations.mustReferenceTables) {
            for (const t of fx.expectations.mustReferenceTables) {
              expect(
                features.tables.includes(t.toLowerCase()),
                `must reference ${t}; got: [${features.tables.join(", ")}]`,
              ).toBe(true);
            }
          }
          if (fx.expectations.mustNotReference) {
            for (const t of fx.expectations.mustNotReference) {
              expect(
                features.tables.includes(t.toLowerCase()),
                `must NOT reference ${t}`,
              ).toBe(false);
            }
          }
          if (fx.expectations.mustContainKeywords) {
            for (const kw of fx.expectations.mustContainKeywords) {
              expect(
                features.scrubbed.includes(kw.toLowerCase()),
                `must contain "${kw}"; sql=${object.sql}`,
              ).toBe(true);
            }
          }
          if (fx.expectations.mustNotContainKeywords) {
            for (const kw of fx.expectations.mustNotContainKeywords) {
              expect(
                features.scrubbed.includes(kw.toLowerCase()),
                `must NOT contain "${kw}"; sql=${object.sql}`,
              ).toBe(false);
            }
          }
          if (fx.expectations.mustHaveAliases) {
            expect(features.hasAlias, "must use AS aliases").toBe(true);
          }
        },
        45_000,
      );
    }
  },
);
