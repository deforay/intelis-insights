"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { History, MessageSquare, X, AlertCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface SessionItem {
  id: string;
  title: string | null;
  updatedAt: string;
}

type LoadState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "loaded"; sessions: SessionItem[] }
  | { kind: "error"; message: string };

export function SessionsMenu() {
  const [open, setOpen] = useState(false);
  const [state, setState] = useState<LoadState>({ kind: "idle" });

  useEffect(() => {
    if (!open) return;
    if (state.kind === "loaded" || state.kind === "loading") return;
    let canceled = false;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setState({ kind: "loading" });
    (async () => {
      try {
        const res = await fetch("/api/v1/sessions");
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!canceled) {
          setState({ kind: "loaded", sessions: data.sessions ?? [] });
        }
      } catch (err) {
        if (!canceled) {
          setState({ kind: "error", message: (err as Error).message });
        }
      }
    })();
    return () => {
      canceled = true;
    };
  }, [open, state.kind]);

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
              {state.kind === "loading" || state.kind === "idle" ? (
                <div className="space-y-2">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <div
                      key={i}
                      className="h-14 rounded-lg border bg-muted animate-pulse"
                    />
                  ))}
                </div>
              ) : state.kind === "error" ? (
                <div className="flex flex-col items-center justify-center px-3 py-16 gap-2 text-center">
                  <div className="flex size-10 items-center justify-center rounded-full bg-destructive/10">
                    <AlertCircle className="size-4 text-destructive" />
                  </div>
                  <div className="text-sm font-medium">Couldn’t load history</div>
                  <div className="text-xs text-muted-foreground">
                    {state.message}
                  </div>
                </div>
              ) : state.sessions.length === 0 ? (
                <div className="flex flex-col items-center justify-center px-3 py-16 gap-2 text-center">
                  <div className="flex size-10 items-center justify-center rounded-full bg-muted">
                    <MessageSquare className="size-4 text-muted-foreground" />
                  </div>
                  <div className="text-sm font-medium">No conversations yet</div>
                  <div className="text-xs text-muted-foreground">
                    Ask a question to start one.
                  </div>
                </div>
              ) : (
                <ul className="flex flex-col gap-1">
                  {state.sessions.map((s) => (
                    <li key={s.id}>
                      <Link
                        href={`/chat/${s.id}`}
                        prefetch={false}
                        onClick={() => setOpen(false)}
                        className="group flex items-start gap-2.5 rounded-lg border border-transparent px-3 py-2.5 hover:border-border hover:bg-muted/50 transition-colors"
                      >
                        <MessageSquare className="size-3.5 mt-0.5 shrink-0 text-muted-foreground group-hover:text-primary transition-colors" />
                        <div className="flex-1 min-w-0">
                          <div className="text-sm truncate text-foreground">
                            {s.title ?? "Untitled conversation"}
                          </div>
                          <div className="text-[10px] text-muted-foreground/80 mt-0.5">
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
