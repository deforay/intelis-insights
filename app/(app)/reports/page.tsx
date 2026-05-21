import Link from "next/link";
import { redirect } from "next/navigation";
import { Bookmark } from "lucide-react";
import { auth } from "@/auth";
import { listReports } from "@/lib/reports/store";
import { Topbar } from "@/components/app-shell/topbar";
import { ReportCard } from "@/components/dashboard/report-card";

export const dynamic = "force-dynamic";

export const metadata = {
  title: "Reports — InteLIS Insights",
};

export default async function ReportsPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const reports = await listReports({ userId: session.user.id });

  const pinned = reports.filter((r) => r.pinned);
  const unpinned = reports.filter((r) => !r.pinned);

  return (
    <>
      <Topbar session={session} title="Reports" />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-7xl w-full px-6 md:px-10 lg:px-14 py-8">
          <div className="mb-6">
            <h2 className="text-2xl font-semibold tracking-tight">
              All reports
            </h2>
            <p className="text-sm text-muted-foreground mt-0.5">
              Your saved questions. Pin the ones you want on your dashboard.
            </p>
          </div>

          {reports.length === 0 ? (
            <div className="rounded-2xl border bg-card/40 backdrop-blur p-12 text-center">
              <div className="flex justify-center mb-3">
                <Bookmark className="size-6 text-muted-foreground" />
              </div>
              <h3 className="text-base font-semibold mb-1">
                No saved reports yet
              </h3>
              <p className="text-sm text-muted-foreground">
                After asking a question,{" "}
                <Link href="/chat" className="text-primary underline">
                  save the answer
                </Link>{" "}
                to come back to it.
              </p>
            </div>
          ) : (
            <div className="flex flex-col gap-8">
              {pinned.length > 0 && (
                <section className="flex flex-col gap-3">
                  <h3 className="text-[11px] uppercase tracking-wider text-muted-foreground">
                    Pinned · {pinned.length}
                  </h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {pinned.map((r) => (
                      <ReportCard
                        key={r.id}
                        id={r.id}
                        title={r.title}
                        question={r.question}
                        pinned={r.pinned}
                        lastSummary={r.lastSummary}
                        lastRunAt={
                          r.lastRunAt ? r.lastRunAt.toISOString() : null
                        }
                      />
                    ))}
                  </div>
                </section>
              )}
              {unpinned.length > 0 && (
                <section className="flex flex-col gap-3">
                  <h3 className="text-[11px] uppercase tracking-wider text-muted-foreground">
                    Library · {unpinned.length}
                  </h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {unpinned.map((r) => (
                      <ReportCard
                        key={r.id}
                        id={r.id}
                        title={r.title}
                        question={r.question}
                        pinned={r.pinned}
                        lastSummary={r.lastSummary}
                        lastRunAt={
                          r.lastRunAt ? r.lastRunAt.toISOString() : null
                        }
                      />
                    ))}
                  </div>
                </section>
              )}
            </div>
          )}
        </div>
      </main>
    </>
  );
}
