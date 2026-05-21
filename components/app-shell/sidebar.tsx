import Link from "next/link";
import { Plus, MessageSquare, FlaskConical } from "lucide-react";
import { auth } from "@/auth";
import { listSessions } from "@/lib/chat/sessions";
import { buttonVariants } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";

export async function Sidebar({ activeSessionId }: { activeSessionId?: string }) {
  const session = await auth();
  const sessions = session?.user ? await listSessions(session.user.id) : [];

  return (
    <aside className="hidden md:flex w-64 shrink-0 flex-col border-r bg-card/40">
      <div className="flex items-center gap-2 px-4 py-3.5 border-b">
        <div className="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
          <FlaskConical className="size-4" />
        </div>
        <div className="flex flex-col leading-tight">
          <span className="text-sm font-semibold">InteLIS Insights</span>
          <span className="text-[10px] text-muted-foreground">FOSS · AGPLv3</span>
        </div>
      </div>

      <div className="px-3 py-3">
        <Link
          href="/chat"
          className={cn(
            buttonVariants({ variant: "default", size: "default" }),
            "w-full justify-start",
          )}
        >
          <Plus className="size-4" />
          New chat
        </Link>
      </div>

      <Separator />

      <div className="flex items-center justify-between px-4 pt-3 pb-2">
        <span className="text-[10px] uppercase tracking-wider text-muted-foreground">
          Recent
        </span>
      </div>

      <ScrollArea className="flex-1 px-2">
        {sessions.length === 0 ? (
          <div className="px-2 py-6 text-xs text-muted-foreground">
            No conversations yet.
          </div>
        ) : (
          <ul className="flex flex-col gap-0.5 pb-4">
            {sessions.map((s) => (
              <li key={s.id}>
                <Link
                  href={`/chat/${s.id}`}
                  className={cn(
                    "flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-foreground/80 hover:bg-muted hover:text-foreground transition-colors",
                    activeSessionId === s.id && "bg-muted text-foreground",
                  )}
                >
                  <MessageSquare className="size-3.5 shrink-0 text-muted-foreground" />
                  <span className="truncate">
                    {s.title ?? "Untitled conversation"}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </ScrollArea>
    </aside>
  );
}
