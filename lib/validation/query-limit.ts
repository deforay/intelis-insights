import { Parser } from "node-sql-parser";
import { SCOPE_LIMITS } from "@/lib/config/business-rules";

const parser = new Parser();

type SqlAstNode = Record<string, unknown>;

export function clampResultLimit(sql: string): string {
  const trimmed = sql.replace(/;\s*$/, "");
  const astRaw = parser.astify(trimmed, { database: "MySQL" });

  if (Array.isArray(astRaw)) {
    throw new Error("cannot apply a result limit to multiple SQL statements");
  }

  const ast = astRaw as unknown as SqlAstNode;
  if (!hasLimit(ast.limit)) {
    return `${trimmed} LIMIT ${SCOPE_LIMITS.maxResultLimit}`;
  }

  clampLimitAst(ast.limit);
  return parser.sqlify(ast as never, { database: "MySQL" });
}

function hasLimit(limitRaw: unknown): boolean {
  return (
    isRecord(limitRaw) &&
    Array.isArray(limitRaw.value) &&
    limitRaw.value.length > 0
  );
}

function clampLimitAst(limitRaw: unknown): void {
  if (!isRecord(limitRaw) || !Array.isArray(limitRaw.value)) return;

  // MySQL supports both LIMIT count and LIMIT offset, count. The row-count
  // operand is the last numeric value in node-sql-parser's representation.
  for (let i = limitRaw.value.length - 1; i >= 0; i -= 1) {
    const item = limitRaw.value[i];
    if (!isRecord(item) || item.type !== "number") continue;
    const value = Number(item.value);
    if (Number.isFinite(value) && value > SCOPE_LIMITS.maxResultLimit) {
      item.value = SCOPE_LIMITS.maxResultLimit;
    }
    return;
  }
}

function isRecord(value: unknown): value is SqlAstNode {
  return typeof value === "object" && value !== null;
}
