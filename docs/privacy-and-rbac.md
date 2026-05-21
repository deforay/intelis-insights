# Privacy & RBAC

## Privacy invariants

InteLIS Insights is built for healthcare data in public-health systems where patient privacy is non-negotiable. These invariants are enforced by code, not by policy:

### 1. The LLM never holds a database connection

The LLM receives text (the user's question, the database schema, business rules) and emits text (SQL plus structured metadata). The InteLIS Insights application — not the LLM — opens the DB connection, validates the SQL, and runs it.

There is no tool-calling-with-DB-access pattern. No agentic-with-credentials loop. Every SQL execution passes through `validate-access` and `validate-query` no matter what the LLM produces.

### 2. No patient identifiers are sent to any LLM

The LLM sees the user's question text and the schema (table names, column descriptions, business rules, clinical thresholds). It never sees:

- Patient names, IDs, ART numbers, child IDs, mother IDs.
- Phone numbers, email addresses, postal addresses.
- Query result rows.

A list of **forbidden columns** is enforced before any SQL runs:

```
patient_first_name, patient_last_name, patient_id, patient_art_no,
child_id, child_name, child_surname,
mother_id, mother_name, mother_surname,
system_patient_code,
facility_email, contact_person_email, contact_person_phone
```

Carve-out: these columns may appear inside `COUNT(DISTINCT …)` only (to enable aggregate counts of unique patients without exposing any individual). Anything else with one of these column names anywhere in the generated SQL is rejected before execution.

### 3. Conversation history is sanitised before re-use

When the user asks a follow-up ("of those, in Littoral"), prior-turn SQL is replayed into the LLM as context. That replayed SQL goes through the same forbidden-column scrub — so a stray patient ID a user pasted into an earlier turn does not leak into the next prompt.

### 4. All generated SQL is validated

Before any SQL runs against the InteLIS database:

- **SELECT-only.** No UPDATE, DELETE, INSERT, CREATE, ALTER, TRUNCATE, EXEC, UNION, multi-statement.
- **FROM clause required.**
- **Table allow-list.** Every referenced table must be in the schema allow-list derived from the exported InteLIS schema.
- **No forbidden PII columns** (see above).
- **Hard `LIMIT 10000`** injected if missing.

Failures route to an error response with an explicit reason — never silent execution.

### 5. Audit log on every query

Every query records: user, timestamp, NL question, generated SQL, post-access-control SQL, scope decision, row count, error stage (if any), trace ID, LLM provider/model. Available to admins through the app and to operators via the `audit_log` Postgres table.

## RBAC — Role-Based Access Control

### Access levels

| Level | What the user can query |
|---|---|
| `district` | A single district only. |
| `multi_district` | A specific list of districts. |
| `province` | A single province (all its districts). |
| `multi_province` | A specific list of provinces. |
| `national` | Everything. |

Each user record carries:

```ts
{
  accessLevel: 'district' | 'multi_district' | 'province' | 'multi_province' | 'national',
  allowedProvinces: string[],   // e.g. ['LT', 'CE']
  allowedDistricts: string[],
}
```

### Enforcement

RBAC is enforced in the `validate-access` graph node — **after** the LLM generates SQL, **before** the SQL runs.

The node parses the generated SQL using `node-sql-parser`. For non-national users, it ensures every reference to `facility_details` or `geographical_divisions` is constrained by a `WHERE` / `HAVING` clause matching the user's allowed scope:

- **Missing constraint** → inject it. The SQL is rewritten before execution.
- **Cross-scope constraint** → reject. If a district-level user has `['LT']` and the SQL specifies `WHERE facility_state = 'CE'`, the request fails with an explicit reason; we never silently rewrite a clearly cross-scope intent.
- **Too complex to safely rewrite** (nested subqueries, CTEs the parser can't reason about) → reject.

!!! warning "Defence in depth, not LLM trust"
    The LLM prompt asks for scope-respecting SQL, but RBAC does not rely on the LLM following instructions. Even if the LLM ignored the prompt completely, the validate-access node enforces scope at the AST level before execution.

### Session-scoped user context

The user's identity and RBAC fields are derived **server-side** from the Auth.js session cookie. They are never accepted from request bodies, query strings, or client-side state. A user cannot escalate by tampering with what the browser sends.

## Audit example

For a query like:

> *User: "What's the VL suppression rate by district last quarter?"*

The `audit_log` row records:

| Column | Example |
|---|---|
| `user_id` | `uuid` |
| `question` | "What's the VL suppression rate by district last quarter?" |
| `generated_sql` | `SELECT ... GROUP BY ...` (the LLM's raw output) |
| `rewritten_sql` | the same SQL with any `validate-access` WHERE injections |
| `access_decision` | `{ allowed: true, injections: [...] }` |
| `validation_result` | `{ passed: true }` |
| `result_count` | `42` |
| `duration_ms` | `8200` |
| `trace_id` | `langfuse trace id` |
| `llm_provider` | `openai` |
| `llm_model` | `gpt-4o` |

This is enough to reconstruct exactly what happened and why, months later, in a donor or compliance review.
