/**
 * Regex-based intent + table router.
 *
 * Replaces the retired project's LLM intent classifier with deterministic
 * keyword/phrase matching against the domain vocabulary. Cheaper, faster,
 * and reproducible — the downstream SQL-gen LLM still sees the full
 * question text plus RAG context, so subtle paraphrases are recovered
 * later in the pipeline. If accuracy gaps show up in eval, swap this
 * node's body for a `generateStructured()` call without changing the
 * graph wiring.
 *
 * Patterns ported from:
 *   - `QueryService::detectIntentSimple` (lines 350–374)
 *   - `QueryService::selectRelevantTablesWithBusinessRules` (lines 379–440)
 *   - `ConversationContextService::seemsToReferencePrevious` (lines 319–465)
 */
import type { IntentKind, IntentInfo } from "./types";
import { ALLOWED_TABLES } from "@/lib/config/tables";

interface TableGroup {
  pattern: RegExp;
  tables: string[];
  testType?: string;
}

const TABLE_GROUPS: TableGroup[] = [
  {
    pattern:
      /\b(vl|viral load|hiv|hiv vl|suppression|suppressed|turnaround|tat|test volume|rejection rate|sample)\b/i,
    tables: ["form_vl"],
    testType: "vl",
  },
  {
    pattern: /\b(covid|coronavirus|covid19|covid-19|sars[- ]cov[- ]?2)\b/i,
    tables: ["form_covid19"],
    testType: "covid19",
  },
  {
    pattern: /\b(eid|infant|early infant diagnosis|dna pcr)\b/i,
    tables: ["form_eid"],
    testType: "eid",
  },
  {
    pattern: /\b(tb|tuberculosis|xpert)\b/i,
    tables: ["form_tb"],
    testType: "tb",
  },
  {
    pattern: /\b(cd4)\b/i,
    tables: ["form_cd4"],
    testType: "cd4",
  },
  {
    pattern: /\b(hepatitis|hep[ -]?[bc])\b/i,
    tables: ["form_hepatitis"],
    testType: "hepatitis",
  },
  {
    pattern: /\b(facility|facilities|clinic|lab)\b/i,
    tables: ["facility_details"],
  },
  {
    pattern: /\b(batch|batches)\b/i,
    tables: ["batch_details"],
  },
  {
    pattern: /\b(user|users|staff|operator)\b/i,
    tables: ["user_details"],
  },
];

const TEST_FORM_TABLES = new Set([
  "form_vl",
  "form_eid",
  "form_covid19",
  "form_tb",
  "form_hepatitis",
  "form_cd4",
  "form_generic",
]);

const TEST_DATA_SIGNAL =
  /\b(turnaround|average|count|total|rate|volume|monthly|yearly|trend|how many|number of)\b/i;

const REFERENCE_PRONOUNS = [
  "these",
  "those",
  "them",
  "they",
  "it",
  "same",
  "above",
  "previous",
  "earlier",
] as const;

const REFERENCE_CONTINUATIONS = [
  "of those",
  "among them",
  "from those",
  "filter those",
  "from the above",
  "from the previous",
  "of the above",
  "out of those",
  "within those",
  "from that",
  "of that",
] as const;

const REFERENCE_DRILLDOWNS = [
  "break down",
  "breakdown",
  "by province",
  "by facility",
  "by region",
  "by state",
  "by district",
  "by month",
  "by year",
  "by quarter",
  "by age",
  "by sex",
  "by gender",
  "group by",
  "per facility",
  "per province",
  "per region",
  "per state",
  "per month",
] as const;

const REFERENCE_REFINEMENTS = [
  "but only",
  "just the",
  "narrow to",
  "narrow down",
  "limit to",
  "restrict to",
  "only the",
  "only for",
  "only in",
  "only from",
  "exclude",
  "except",
] as const;

const REFERENCE_FOLLOWUPS = [
  "what about",
  "how about",
  "and also",
  "what percentage",
  "what percent",
  "what proportion",
  "furthermore",
  "additionally",
  "how many of",
  "what fraction",
  "also show",
  "also include",
  "can you also",
  "now show",
  "now give",
  "now list",
  "compare with",
  "compare to",
] as const;

const TABLE_KEYWORD =
  /\b(vl|viral load|hiv|covid|eid|tb|tuberculosis|cd4|hepatitis|facility|clinic|lab|batch|user)\b/i;

export function classifyIntent(args: {
  question: string;
  hasConversationHistory: boolean;
}): IntentInfo {
  const q = args.question.toLowerCase().trim();

  // ── Intent kinds ────────────────────────────────────────────────────
  const intents: IntentKind[] = [];
  if (/\b(how many|count|number of|total)\b/.test(q)) intents.push("count");
  if (/\b(list|show|display|all|get|which|what are)\b/.test(q))
    intents.push("list");
  if (/\b(average|mean|sum|max|min|median|rate|percentage|percent)\b/.test(q))
    intents.push("aggregate");
  if (intents.length === 0) intents.push("general");

  const multiPart =
    intents.length > 1 ||
    /\b(how many|count|number of).*\b(and|also|what is|how much)\b/i.test(q);

  // ── Table + test-type selection ─────────────────────────────────────
  const selectedTables = new Set<string>();
  const testTypes = new Set<string>();
  for (const group of TABLE_GROUPS) {
    if (group.pattern.test(q)) {
      for (const t of group.tables) selectedTables.add(t);
      if (group.testType) testTypes.add(group.testType);
    }
  }

  if (/\b(province|state|district|county|region|zone)\b/i.test(q)) {
    selectedTables.add("geographical_divisions");
  }

  // Bridge: form_vl (and other test forms) join to geographical_divisions
  // THROUGH facility_details. If both a form table and geographical_divisions
  // are selected, pull in facility_details so the SQL generator sees the
  // join path.
  const hasFormTable = Array.from(selectedTables).some((t) =>
    TEST_FORM_TABLES.has(t),
  );
  if (hasFormTable && selectedTables.has("geographical_divisions")) {
    selectedTables.add("facility_details");
  }

  // Default: assume VL when the question is clearly about test data but
  // no test type was named.
  if (selectedTables.size === 0) {
    if (/\b(patient|test|testing|tests|sample|result|results)\b/i.test(q)) {
      selectedTables.add("form_vl");
      testTypes.add("vl");
    } else {
      selectedTables.add("facility_details");
    }
  }

  // If a test signal is present but no test-form table was picked, add VL.
  const hasTestForm = Array.from(selectedTables).some((t) =>
    TEST_FORM_TABLES.has(t),
  );
  if (!hasTestForm && TEST_DATA_SIGNAL.test(q)) {
    selectedTables.add("form_vl");
    testTypes.add("vl");
  }

  // Intersect with the allowlist + cap per business rules.
  const allowSet = new Set<string>(ALLOWED_TABLES);
  const tables = Array.from(selectedTables)
    .filter((t) => allowSet.has(t))
    .slice(0, 3);

  // ── Reference detection ─────────────────────────────────────────────
  const referencesPrevious = detectReference(q, args.hasConversationHistory);

  return {
    type: multiPart ? "multi_part" : "single",
    intents,
    testTypes: Array.from(testTypes),
    tables,
    referencesPrevious,
  };
}

function detectReference(q: string, hasHistory: boolean): boolean {
  if (!hasHistory) return false;

  for (const word of REFERENCE_PRONOUNS) {
    const re = new RegExp(`\\b${word}\\b`);
    if (re.test(q)) return true;
  }
  for (const phrase of REFERENCE_CONTINUATIONS) {
    if (q.includes(phrase)) return true;
  }

  const hasTableKeyword = TABLE_KEYWORD.test(q);
  for (const phrase of REFERENCE_DRILLDOWNS) {
    if (q.includes(phrase) && !hasTableKeyword) return true;
  }
  for (const phrase of REFERENCE_REFINEMENTS) {
    if (q.includes(phrase)) return true;
  }
  for (const phrase of REFERENCE_FOLLOWUPS) {
    if (q.includes(phrase)) return true;
  }

  // Short, table-keyword-less follow-ups in mid-conversation.
  const wordCount = q.split(/\s+/).filter(Boolean).length;
  if (wordCount < 6 && !hasTableKeyword) return true;

  return false;
}
