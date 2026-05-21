import { sql } from "drizzle-orm";
import {
  index,
  integer,
  jsonb,
  pgEnum,
  pgTable,
  text,
  timestamp,
  uuid,
} from "drizzle-orm/pg-core";

export const accessLevelEnum = pgEnum("access_level", [
  "district",
  "multi_district",
  "province",
  "multi_province",
  "national",
]);

export const userRoleEnum = pgEnum("user_role", ["admin", "user"]);

export const messageRoleEnum = pgEnum("message_role", [
  "user",
  "assistant",
  "system",
]);

export const users = pgTable("users", {
  id: uuid("id").primaryKey().defaultRandom(),
  email: text("email").notNull().unique(),
  passwordHash: text("password_hash").notNull(),
  name: text("name"),
  role: userRoleEnum("role").notNull().default("user"),
  accessLevel: accessLevelEnum("access_level").notNull().default("district"),
  allowedProvinces: text("allowed_provinces")
    .array()
    .notNull()
    .default(sql`'{}'::text[]`),
  allowedDistricts: text("allowed_districts")
    .array()
    .notNull()
    .default(sql`'{}'::text[]`),
  createdAt: timestamp("created_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true })
    .notNull()
    .defaultNow(),
});

export const chatSessions = pgTable(
  "chat_sessions",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    userId: uuid("user_id")
      .notNull()
      .references(() => users.id, { onDelete: "cascade" }),
    title: text("title"),
    createdAt: timestamp("created_at", { withTimezone: true })
      .notNull()
      .defaultNow(),
    updatedAt: timestamp("updated_at", { withTimezone: true })
      .notNull()
      .defaultNow(),
  },
  (t) => [index("idx_chat_sessions_user_id").on(t.userId)],
);

export const chatMessages = pgTable(
  "chat_messages",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    sessionId: uuid("session_id")
      .notNull()
      .references(() => chatSessions.id, { onDelete: "cascade" }),
    role: messageRoleEnum("role").notNull(),
    content: text("content").notNull(),
    queryResult: jsonb("query_result"),
    chart: jsonb("chart"),
    createdAt: timestamp("created_at", { withTimezone: true })
      .notNull()
      .defaultNow(),
  },
  (t) => [index("idx_chat_messages_session_id").on(t.sessionId)],
);

export const auditLog = pgTable(
  "audit_log",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    userId: uuid("user_id").references(() => users.id, {
      onDelete: "set null",
    }),
    sessionId: uuid("session_id").references(() => chatSessions.id, {
      onDelete: "set null",
    }),
    question: text("question").notNull(),
    generatedSql: text("generated_sql"),
    rewrittenSql: text("rewritten_sql"),
    accessDecision: jsonb("access_decision"),
    validationResult: jsonb("validation_result"),
    resultCount: integer("result_count"),
    durationMs: integer("duration_ms"),
    errorStage: text("error_stage"),
    errorMessage: text("error_message"),
    traceId: text("trace_id"),
    llmProvider: text("llm_provider"),
    llmModel: text("llm_model"),
    createdAt: timestamp("created_at", { withTimezone: true })
      .notNull()
      .defaultNow(),
  },
  (t) => [
    index("idx_audit_log_user_id").on(t.userId),
    index("idx_audit_log_created_at").on(t.createdAt),
  ],
);

export type User = typeof users.$inferSelect;
export type NewUser = typeof users.$inferInsert;
export type ChatSession = typeof chatSessions.$inferSelect;
export type ChatMessage = typeof chatMessages.$inferSelect;
export type AuditLog = typeof auditLog.$inferSelect;
export type NewAuditLog = typeof auditLog.$inferInsert;
