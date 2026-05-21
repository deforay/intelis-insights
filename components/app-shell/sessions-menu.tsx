"use client";

import { useEffect, useState, useTransition } from "react";
import Link from "next/link";
import { History, MessageSquare, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface SessionItem {
  id: string;
  title: string | null;
  updatedAt: string;
}

export function SessionsMenu() {
  const [open, setOpen] = useState(false);
  const [sessions, setSessions] = useState<SessionItem[] | null>(null);
  const [loading, startLoading] = useTransition();

  useEffect(() => {
    if (!open || sessions) return;
    startLoading(async () => {
      try {
        const res = await fetch("/api/v1/sessions");
        const data = await res.json();
        setSessions(data.sessions ?? []);
      } catch {
        setSessions([]);
      }
    });
  }, [open, sessions]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  return (
    <>
      <Button
        variant="ghost"
        size="sm"
        onClick={() => setOpen(true)}
        className="gap-1.5"
      >
        <History className="size-3.5" />
        History
      </Button>

      {open && (
        <>
          <div
            className="fixed inset-0 z-40 bg-background/40 backdrop-blur-sm"
            onClick={() => setOpen(false)}
          />
          <div
            className={cn(
              "fixed inset-y-0 right-0 z-50 w-full max-w-md border-l bg-card/95 backdrop-blur-xl",
              "flex flex-col shadow-2xl",
            )}
          >
            <div className="flex items-center justify-between px-5 py-4 border-b">
              <div>
                <div className="text-sm font-semibold">History</div>
                <div className="text-[11px] text-muted-foreground">
                  Your past conversations
                </div>
              </div>
              <Button
                variant="ghost"
                size="icon-sm"
                onClick={() => setOpen(false)}
                aria-label="Close"
              >
                <X className="size-4" />
              </Button>
            </div>
            <div className="flex-1 overflow-y-auto px-3 py-3">
              {loading ? (
                <div className="space-y-2">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <div
                      key={i}
                      className="h-12 rounded-lg bg-muted/30 animate-pulse"
                    />
                  ))}
                </div>
              ) : !sessions || sessions.length === 0 ? (
                <div className="px-3 py-12 text-center text-sm text-muted-foreground">
                  No conversations yet.
                </div>
              ) : (
                <ul className="flex flex-col gap-1">
                  {sessions.map((s) => (
                    <li key={s.id}>
                      <Link
                        href={`/chat/${s.id}`}
                        prefetch={false}
                        onClick={() => setOpen(false)}
                        className="group flex items-start gap-2.5 rounded-lg px-3 py-2.5 hover:bg-muted/40 transition-colors"
                      >
                        <MessageSquare className="size-3.5 mt-0.5 shrink-0 text-muted-foreground group-hover:text-primary transition-colors" />
                        <div className="flex-1 min-w-0">
                          <div className="text-sm truncate">
                            {s.title ?? "Untitled conversation"}
                          </div>
                          <div className="text-[10px] text-muted-foreground/70 mt-0.5">
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
          </div>
        </>
      )}
    </>
  );
}
