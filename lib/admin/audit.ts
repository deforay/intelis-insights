/**
 * Admin audit-log queries.
 *
 * One row per query — surfaces who asked what, what SQL was generated
 * (and how it was rewritten by RBAC), how many rows it returned, how
 * long it took, and any error along the way.
 */
import { and, desc, eq, isNotNull, sql } from "drizzle-orm";
import { db } from "@/lib/db/app";
import { auditLog, users } from "@/lib/db/schema";

export interface AuditRow {
  id: string;
  userId: string | null;
  userEmail: string | null;
  sessionId: string | null;
  question: string;
  generatedSql: string | null;
  rewrittenSql: string | null;
  accessDecision: unknown;
  validationResult: unknown;
  resultCount: number | null;
  durationMs: number | null;
  errorStage: string | null;
  errorMessage: string | null;
  traceId: string | null;
  llmProvider: string | null;
  llmModel: string | null;
  createdAt: Date;
}

const DEFAULT_LIMIT = 100;

export async function listAuditLog(args: {
  limit?: number;
  userId?: string;
  traceId?: string;
  errorsOnly?: boolean;
} = {}): Promise<AuditRow[]> {
  const limit = args.limit ?? DEFAULT_LIMIT;
  const filters = [
    args.userId ? eq(auditLog.userId, args.userId) : undefined,
    args.traceId ? eq(auditLog.traceId, args.traceId) : undefined,
    args.errorsOnly ? isNotNull(auditLog.errorStage) : undefined,
  ].filter((filter) => filter !== undefined);

  const base = db
    .select({
      id: auditLog.id,
      userId: auditLog.userId,
      userEmail: users.email,
      sessionId: auditLog.sessionId,
      question: auditLog.question,
      generatedSql: auditLog.generatedSql,
      rewrittenSql: auditLog.rewrittenSql,
      accessDecision: auditLog.accessDecision,
      validationResult: auditLog.validationResult,
      resultCount: auditLog.resultCount,
      durationMs: auditLog.durationMs,
      errorStage: auditLog.errorStage,
      errorMessage: auditLog.errorMessage,
      traceId: auditLog.traceId,
      llmProvider: auditLog.llmProvider,
      llmModel: auditLog.llmModel,
      createdAt: auditLog.createdAt,
    })
    .from(auditLog)
    .leftJoin(users, eq(auditLog.userId, users.id))
    .orderBy(desc(auditLog.createdAt))
    .limit(limit);

  if (filters.length > 0) {
    return base.where(and(...filters));
  }

  return base;
}

export interface AuditSummary {
  total: number;
  successCount: number;
  errorCount: number;
  avgDurationMs: number | null;
  totalTokensIn: number | null;
  totalTokensOut: number | null;
}

export async function auditSummary(): Promise<AuditSummary> {
  const [row] = await db
    .select({
      total: sql<number>`COUNT(*)::int`,
      successCount: sql<number>`COUNT(*) FILTER (WHERE ${auditLog.errorStage} IS NULL)::int`,
      errorCount: sql<number>`COUNT(*) FILTER (WHERE ${auditLog.errorStage} IS NOT NULL)::int`,
      avgDurationMs: sql<number>`AVG(${auditLog.durationMs})::int`,
      totalTokensIn: sql<number>`SUM((${auditLog.validationResult} -> 'tokenUsage' ->> 'inputTokens')::int)::int`,
      totalTokensOut: sql<number>`SUM((${auditLog.validationResult} -> 'tokenUsage' ->> 'outputTokens')::int)::int`,
    })
    .from(auditLog);
  return row ?? {
    total: 0,
    successCount: 0,
    errorCount: 0,
    avgDurationMs: null,
    totalTokensIn: null,
    totalTokensOut: null,
  };
}
