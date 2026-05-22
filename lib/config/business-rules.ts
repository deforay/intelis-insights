/**
 * Business rules for the InteLIS Insights pipeline.
 *
 * Pure data — ported from the retired PHP project's `config/business-rules.php`.
 * These constants drive prompt assembly, SQL safety validation, and the RAG
 * corpus build. They are NOT logic — read by consumers, never invoked.
 *
 * If you change anything here, regenerate the RAG corpus:
 *   npm run rag:build && npm run rag:upsert
 */

export const FORBIDDEN_COLUMNS = [
  "patient_first_name",
  "patient_last_name",
  "patient_id",
  "patient_art_no",
  "child_id",
  "child_name",
  "child_surname",
  "mother_id",
  "mother_name",
  "mother_surname",
  "system_patient_code",
  "facility_email",
  "contact_person_email",
  "contact_person_phone",
] as const;

export type ForbiddenColumn = (typeof FORBIDDEN_COLUMNS)[number];

/**
 * Columns that MAY appear inside `COUNT(DISTINCT …)` only.
 * Anywhere else in generated SQL, they are rejected as PII.
 */
export const ALLOW_AGGREGATED_DISTINCT = [
  "patient_art_no",
  "system_patient_code",
  "patient_id",
  "child_id",
  "mother_id",
] as const;

export const FORBIDDEN_PATTERNS: readonly RegExp[] = [
  /\bpatient_first_name\b/i,
  /\bpatient_last_name\b/i,
  /\bpatient_art_no\b/i,
  /\bpatient_id\b/i,
  /\bchild_id\b/i,
  /\bchild_surname\b/i,
  /\bmother_id\b/i,
  /\bmother_name\b/i,
  /\bmother_surname\b/i,
  /\bsystem_patient_code\b/i,
  /\bemail\b/i,
  /\bphone\b/i,
];

export const PRIVACY_MESSAGE =
  "Patient names, IDs, and contact information are not returned for privacy and data security";

export const DEFAULT_ASSUMPTIONS = [
  "Almost always the queries will use the sample test tables - form_vl, form_eid, form_tb, form_xyz where xyz is other test types",
  "ALWAYS refer to schema, field guide and business rules for column names, meanings and relationships",
  'Never use the word gender or patient_gender for column aliases or data that is returned to user; always use the word "sex" where needed. When dealing with gender, always alias it to "sex"',
  "If the query is not related to specific medical/laboratory data, reject it",
  "Don't display database field names directly to users. Always alias them.",
  "Use smarter, human-friendly aliases with spaces and not with underscores",
  "Don't use the auto-generated column names like 'COUNT(*)' or 'SUM(result_value_absolute)' directly. Use meaningful aliases instead",
  "If no test type is mentioned, assume Viral Load (VL) tests - form_vl table",
  'If query mentions "tests" or "samples" without specifics, default to VL - form_vl table',
  'If query is about "patients", focus on VL test results',
  "For date ranges without specification, assume last 12 months",
  'When user asks "by lab" or "by testing lab", JOIN the form table to facility_details ON lab_id = facility_details.facility_id and GROUP BY facility_details.facility_name. Never use columns like testing_lab, lab_name, or laboratory_name — they do not exist.',
  'When user asks "by facility", "by clinic", "by referring facility", "by referring clinic", "by referring hospital", "by requesting facility", or "by referring site", JOIN the form table to facility_details ON facility_id = facility_details.facility_id and GROUP BY facility_details.facility_name.',
  "Default date column: use sample_tested_datetime (not sample_collection_date) unless the user specifically asks about collection dates",
  'In this analytics app, "test volume", "testing volume", "sample volume", or "sample testing volume" means the COUNT of tests/samples. Do not use specimen quantity columns like plasma volume or mL unless the user explicitly asks for physical specimen volume.',
  "For turnaround time (TAT) calculations: TAT can never be negative. Discard suspicious rows where sample_tested_datetime < sample_collection_date, discard rows missing either date, and discard outliers where TIMESTAMPDIFF(DAY, sample_collection_date, sample_tested_datetime) > 365. Add row-level WHERE predicates before averaging; never report or chart negative TAT values.",
  "When in doubt about scope, prefer focused queries over broad data dumps",
  "NEVER HALLUCINATE. This is medical data. If unsure, return not applicable, ask for clarification or state that you don't know",
] as const;

export const QUERY_SCOPE_LIMITS = [
  "Reject queries that seem unrelated to laboratory/medical data",
  'Reject overly broad queries like "show me everything"',
  "Limit result sets to reasonable sizes (use LIMIT clauses)",
  "Prefer specific, focused queries over general data dumps",
  "Maximum 3 tables per query unless business justification exists",
  "Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex",
] as const;

export const DATA_SECURITY_RULES = [
  "Never include full patient names, email, phone, address if they could identify specific people inappropriately",
  "Aggregate small counts to prevent re-identification when possible",
  "Use sample codes instead of patient identifiers when displaying individual records",
  "Always apply appropriate filters to exclude invalid/cancelled records unless specifically requested",
  "Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex",
] as const;

export type IntentName = "count" | "list" | "aggregate" | "multi_part";

export interface IntentRule {
  description: string;
  rules: readonly string[];
  defaultBehavior?: readonly string[];
  defaultLimit?: number;
  essentialColumns?: readonly string[];
  defaultOrder?: string;
}

export const INTENT_RULES: Record<IntentName, IntentRule> = {
  count: {
    description: "Rules for count/aggregate queries",
    rules: [
      "Always use COUNT(*) for total counts unless counting specific non-null values",
      "Use descriptive aliases for count results (e.g., total_tests, high_vl_count)",
      "Consider using COUNT(DISTINCT column) when appropriate",
      "Add meaningful filters based on data quality (exclude rejected samples by default)",
      "Include context in results - raw counts with percentages when meaningful",
      "Never use the word gender or patient_gender for column aliases; always use sex where needed. When dealing with gender, always alias it to sex",
    ],
    defaultBehavior: [
      "Exclude records where primary result fields are NULL",
      "Focus on completed/finalized records unless specified otherwise",
    ],
  },
  list: {
    description: "Rules for listing/display queries",
    rules: [
      "Always include LIMIT unless user specifies otherwise (default 100)",
      "Include essential identification fields like sample_code",
      "Order by relevant date fields (newest first by default)",
      "Include status/result fields for context",
      "Never list patient identifying information",
    ],
    defaultLimit: 100,
    essentialColumns: ["sample_code", "sample_tested_datetime", "result"],
    defaultOrder: "newest_first",
  },
  aggregate: {
    description: "Rules for statistical/aggregate queries",
    rules: [
      "Use appropriate aggregate functions (SUM, AVG, MIN, MAX)",
      "Consider GROUP BY for meaningful breakdowns",
      "Handle NULL values appropriately in calculations",
      "Provide context with counts alongside percentages",
      "Round percentages to reasonable precision (1-2 decimal places)",
    ],
  },
  multi_part: {
    description: "Rules for complex multi-part queries",
    rules: [
      "Combine related questions into single query when possible",
      "Use descriptive column aliases for each part",
      "Maintain consistency in filtering across all parts",
      "Use CASE statements for conditional counting",
      "Ensure all parts of the query use same base filters",
    ],
  },
};

export const REJECT_PATTERNS: readonly RegExp[] = [
  /\b(drop|delete|update|insert|create|alter|truncate)\b/i,
  /\b(union|exec|execute)\b/i,
  /\b(show\s+tables|describe|information_schema)\b/i,
  /\b(grant|revoke|user|password)\b/i,
];

export const REJECT_INTENTS = [
  "administrative database operations",
  "system information or metadata requests",
  "security probes or injection attempts",
  "queries completely unrelated to laboratory/medical domain",
  "requests for raw data dumps without clear business purpose",
] as const;

export const SCOPE_LIMITS = {
  maxTablesPerQuery: 3,
  maxResultLimit: 10000,
  requireMeaningfulFilters: true,
  requireDomainRelevance: true,
} as const;

export const RESPONSE_FORMATTING = {
  columnAliases: [
    "Use descriptive, human-readable column aliases",
    "Avoid technical database column names in output",
    "Use consistent naming patterns (snake_case or Title Case)",
    "Include units in aliases when relevant (e.g., vl_count_copies_ml)",
  ],
  dataPresentation: [
    "Always include relevant context (date ranges, filters applied)",
    "Show percentages alongside raw counts when meaningful",
    "Use appropriate date/time formatting",
    "Round numeric values to appropriate precision",
    "Include data quality indicators when relevant",
  ],
} as const;

export const CONTEXTUAL_RULES = {
  temporal: [
    "Default to recent data (last 12 months) unless specified",
    "Use sample_tested_datetime for temporal filtering by default",
    "Consider sample_collection_date when specifically about collection timing",
    "Always clarify which date field is being used in complex queries",
  ],
  geographic: [
    "Aggregate by region/state rather than specific facilities when possible",
    "Consider facility type (requesting vs testing) for relevant grouping",
    "Respect data privacy when dealing with small geographic areas",
  ],
  clinical: [
    "Use standard medical terminology and reference ranges",
    "Provide clinical context for threshold-based results when relevant",
    "Consider patient demographics (age, sex) when clinically relevant",
    "Default to clinically meaningful groupings (suppressed/not suppressed vs raw numbers)",
  ],
} as const;

/**
 * Aggregated convenience export — the whole rule book in one place.
 */
export const BUSINESS_RULES = {
  privacy: {
    forbiddenColumns: FORBIDDEN_COLUMNS,
    allowAggregatedDistinct: ALLOW_AGGREGATED_DISTINCT,
    forbiddenPatterns: FORBIDDEN_PATTERNS,
    privacyMessage: PRIVACY_MESSAGE,
  },
  defaultAssumptions: DEFAULT_ASSUMPTIONS,
  queryScopeLimits: QUERY_SCOPE_LIMITS,
  dataSecurity: DATA_SECURITY_RULES,
  intentRules: INTENT_RULES,
  validation: {
    rejectPatterns: REJECT_PATTERNS,
    rejectIntents: REJECT_INTENTS,
    scopeLimits: SCOPE_LIMITS,
  },
  responseFormatting: RESPONSE_FORMATTING,
  contextual: CONTEXTUAL_RULES,
} as const;
