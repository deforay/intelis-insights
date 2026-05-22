import { redirect } from "next/navigation";
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  CircleSlash,
} from "lucide-react";
import { requireAdmin } from "@/lib/auth/admin";
import { auditSummary, listAuditLog } from "@/lib/admin/audit";
import { Topbar } from "@/components/app-shell/topbar";
import { Badge } from "@/components/ui/badge";

export const dynamic = "force-dynamic";

export const metadata = {
  title: "Audit — InteLIS Insights",
};

type AuditSearchParams = Promise<{
  traceId?: string | string[];
  errors?: string | string[];
}>;

export default async function AdminAuditPage({
  searchParams,
}: {
  searchParams: AuditSearchParams;
}) {
  const session = await requireAdmin();
  if (!session) redirect("/chat");

  const params = await searchParams;
  const traceId = firstParam(params.traceId);
  const errorsOnly = firstParam(params.errors) === "1";

  const [rows, summary] = await Promise.all([
    listAuditLog({ limit: traceId ? 1 : 100, traceId, errorsOnly }),
    auditSummary(),
  ]);

  return (
    <>
      <Topbar session={session} title="Audit log" />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-6xl w-full px-4 md:px-8 py-6">
          <div className="mb-4">
            <h2 className="text-base font-semibold">Query audit log</h2>
            <p className="text-sm text-muted-foreground">
              {traceId
                ? `Showing the audit row for trace ${traceId.slice(0, 8)}.`
                : errorsOnly
                  ? "Showing failed natural-language queries. Clear the filter to see all entries."
                  : "Every natural-language query and the SQL it produced, including scope rewrites and errors. Last 100 entries."}
            </p>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <Stat
              icon={<Clock className="size-3.5" />}
              label="Total"
              value={summary.total.toLocaleString()}
            />
            <Stat
              icon={<CheckCircle2 className="size-3.5 text-emerald-500" />}
              label="Successful"
              value={summary.successCount.toLocaleString()}
            />
            <Stat
              icon={<CircleSlash className="size-3.5 text-destructive" />}
              label="Failed"
              value={summary.errorCount.toLocaleString()}
            />
            <Stat
              icon={<Clock className="size-3.5" />}
              label="Avg latency"
              value={
                summary.avgDurationMs != null
                  ? `${(summary.avgDurationMs / 1000).toFixed(2)}s`
                  : "—"
              }
            />
          </div>

          <div className="flex flex-col gap-2">
            {rows.length === 0 ? (
              <div className="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                No queries logged yet.
              </div>
            ) : (
              rows.map((r) => <AuditRowCard key={r.id} row={r} />)
            )}
          </div>
        </div>
      </main>
    </>
  );
}

function firstParam(value: string | string[] | undefined): string | undefined {
  if (Array.isArray(value)) return value[0];
  return value;
}

function Stat({
  icon,
  label,
  value,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
}) {
  return (
    <div className="rounded-lg border bg-card p-3">
      <div className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider text-muted-foreground">
        {icon}
        {label}
      </div>
      <div className="mt-1 text-lg font-semibold">{value}</div>
    </div>
  );
}

function AuditRowCard({
  row,
}: {
  row: Awaited<ReturnType<typeof listAuditLog>>[number];
}) {
  const isError = !!row.errorStage;
  const ts = new Date(row.createdAt).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });
  return (
    <div className="rounded-xl border bg-card overflow-hidden">
      <div className="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b text-[11px] text-muted-foreground">
        <span className="text-foreground/80">
          {row.userEmail ?? "(deleted user)"}
        </span>
        <span>·</span>
        <span>{ts}</span>
        <span>·</span>
        <span>
          {row.durationMs != null ? `${(row.durationMs / 1000).toFixed(2)}s` : "—"}
        </span>
        {row.resultCount != null && (
          <>
            <span>·</span>
            <span>{row.resultCount.toLocaleString()} rows</span>
          </>
        )}
        <div className="ml-auto flex items-center gap-2">
          {row.llmModel && (
            <Badge variant="secondary" className="font-mono font-normal text-[10px]">
              {row.llmModel}
            </Badge>
          )}
          {isError ? (
            <Badge variant="destructive" className="gap-1">
              <AlertTriangle className="size-3" />
              {row.errorStage}
            </Badge>
          ) : (
            <Badge variant="outline" className="gap-1">
              <CheckCircle2 className="size-3 text-emerald-500" />
              OK
            </Badge>
          )}
        </div>
      </div>
      <div className="px-4 py-3 text-sm">
        <div className="font-medium">{row.question}</div>
        {isError && row.errorMessage && (
          <div className="mt-2 text-xs text-destructive">{row.errorMessage}</div>
        )}
      </div>
      {(row.generatedSql || row.rewrittenSql) && (
        <details className="border-t">
          <summary className="cursor-pointer px-4 py-2 text-[11px] text-muted-foreground hover:bg-muted/30">
            SQL
          </summary>
          <div className="px-4 pb-3 flex flex-col gap-2">
            {row.generatedSql && (
              <div>
                <div className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">
                  Generated
                </div>
                <pre className="overflow-x-auto p-2 text-[11px] font-mono rounded-md bg-muted/40 border">
                  {row.generatedSql}
                </pre>
              </div>
            )}
            {row.rewrittenSql && row.rewrittenSql !== row.generatedSql && (
              <div>
                <div className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">
                  After RBAC rewrite
                </div>
                <pre className="overflow-x-auto p-2 text-[11px] font-mono rounded-md bg-muted/40 border">
                  {row.rewrittenSql}
                </pre>
              </div>
            )}
          </div>
        </details>
      )}
    </div>
  );
}
