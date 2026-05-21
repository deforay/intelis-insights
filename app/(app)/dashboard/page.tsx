import Link from "next/link";
import { redirect } from "next/navigation";
import { Bookmark, LayoutDashboard, Plus } from "lucide-react";
import { auth } from "@/auth";
import { listReports } from "@/lib/reports/store";
import { Topbar } from "@/components/app-shell/topbar";
import { ReportCard } from "@/components/dashboard/report-card";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const dynamic = "force-dynamic";

export const metadata = {
  title: "Dashboard — InteLIS Insights",
};

export default async function DashboardPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const pinned = await listReports({
    userId: session.user.id,
    pinnedOnly: true,
  });

  return (
    <>
      <Topbar session={session} title="Dashboard" />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-7xl w-full px-6 md:px-10 lg:px-14 py-8">
          <div className="flex items-center justify-between mb-6">
            <div>
              <h2 className="text-2xl font-semibold tracking-tight">
                Your dashboard
              </h2>
              <p className="text-sm text-muted-foreground mt-0.5">
                Pinned reports — refresh to re-run against the latest data.
              </p>
            </div>
            <Link
              href="/reports"
              className={cn(
                buttonVariants({ variant: "outline", size: "sm" }),
                "gap-1.5",
              )}
            >
              <Bookmark className="size-3.5" />
              All reports
            </Link>
          </div>

          {pinned.length === 0 ? (
            <EmptyDashboard />
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {pinned.map((r) => (
                <ReportCard
                  key={r.id}
                  id={r.id}
                  title={r.title}
                  question={r.question}
                  pinned={r.pinned}
                  lastSummary={r.lastSummary}
                  lastRunAt={r.lastRunAt ? r.lastRunAt.toISOString() : null}
                />
              ))}
            </div>
          )}
        </div>
      </main>
    </>
  );
}

function EmptyDashboard() {
  return (
    <div className="relative rounded-2xl border bg-card/40 backdrop-blur p-12 flex flex-col items-center text-center gap-4 overflow-hidden">
      <div
        className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-primary/60 to-transparent"
      />
      <div className="relative">
        <div className="absolute inset-0 rounded-full bg-primary/20 blur-xl" />
        <div className="relative flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground">
          <LayoutDashboard className="size-5" />
        </div>
      </div>
      <div className="space-y-1.5 max-w-md">
        <h3 className="text-lg font-semibold">Nothing pinned yet</h3>
        <p className="text-sm text-muted-foreground">
          Ask a question, then save the answer to your dashboard. Pinned
          reports show up here with their latest values.
        </p>
      </div>
      <Link
        href="/chat"
        className={cn(buttonVariants({ variant: "default" }), "gap-1.5 mt-2")}
      >
        <Plus className="size-3.5" />
        Ask a question
      </Link>
    </div>
  );
}
