import Link from "next/link";
import { redirect } from "next/navigation";
import { MessageSquare } from "lucide-react";
import { auth } from "@/auth";
import { Topbar } from "@/components/app-shell/topbar";
import { listSessions } from "@/lib/chat/sessions";
import { Card } from "@/components/ui/card";

export const metadata = {
  title: "Sessions — InteLIS Insights",
};

export default async function SessionsPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const sessions = await listSessions(session.user.id);

  return (
    <>
      <Topbar session={session} title="All sessions" />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-3xl w-full px-4 md:px-8 py-6">
          <div className="mb-4">
            <h2 className="text-base font-semibold">Conversations</h2>
            <p className="text-sm text-muted-foreground">
              {sessions.length} {sessions.length === 1 ? "session" : "sessions"}
            </p>
          </div>
          {sessions.length === 0 ? (
            <Card className="p-8 text-center text-sm text-muted-foreground">
              No conversations yet. Start one from{" "}
              <Link href="/chat" className="text-primary underline">
                New chat
              </Link>
              .
            </Card>
          ) : (
            <ul className="flex flex-col gap-2">
              {sessions.map((s) => (
                <li key={s.id}>
                  <Link
                    href={`/chat/${s.id}`}
                    className="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 hover:border-foreground/20 hover:bg-muted/30 transition-colors"
                  >
                    <MessageSquare className="size-4 shrink-0 text-muted-foreground" />
                    <div className="flex-1 min-w-0">
                      <div className="truncate text-sm font-medium">
                        {s.title ?? "Untitled"}
                      </div>
                      <div className="text-[11px] text-muted-foreground">
                        Updated{" "}
                        {new Date(s.updatedAt).toLocaleString(undefined, {
                          dateStyle: "medium",
                          timeStyle: "short",
                        })}
                      </div>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </>
  );
}
