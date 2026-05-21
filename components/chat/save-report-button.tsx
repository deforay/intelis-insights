"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Bookmark, BookmarkCheck, Pin } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import type { AssistantTurn } from "./types";

function buildSummary(turn: AssistantTurn) {
  if (!turn.results) return null;
  const r = turn.results;
  const summary: {
    rowCount: number;
    executionMs: number;
    scalarValue?: number | null;
    firstColumn?: string;
  } = {
    rowCount: r.count,
    executionMs: r.executionMs,
    firstColumn: r.columns[0],
  };
  if (r.count === 1 && r.columns.length <= 2) {
    const row = r.rows[0];
    for (const col of r.columns) {
      const v = row[col];
      if (typeof v === "number") {
        summary.scalarValue = v;
        break;
      }
    }
  }
  return summary;
}

export function SaveReportButton({
  turn,
  questionFromUserTurn,
}: {
  turn: AssistantTurn;
  questionFromUserTurn: string;
}) {
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const router = useRouter();

  const defaultTitle = questionFromUserTurn.replace(/[?.!]+$/, "").slice(0, 80);

  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setSubmitting(true);
    try {
      const res = await fetch("/api/v1/reports", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          title: String(form.get("title")).trim() || defaultTitle,
          question: questionFromUserTurn,
          sql: turn.sql,
          chartConfig: turn.chart,
          lastSummary: buildSummary(turn),
          pinned: form.get("pinned") === "on",
        }),
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error ?? `HTTP ${res.status}`);
      }
      toast.success("Saved to dashboard");
      setSaved(true);
      setOpen(false);
      router.refresh();
    } catch (err) {
      toast.error("Couldn't save", { description: (err as Error).message });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        render={
          <Button
            variant="ghost"
            size="sm"
            className="gap-1.5"
            disabled={saved || !turn.results}
          >
            {saved ? (
              <>
                <BookmarkCheck className="size-3.5 text-primary" />
                Saved
              </>
            ) : (
              <>
                <Bookmark className="size-3.5" />
                Save
              </>
            )}
          </Button>
        }
      />
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Save to dashboard</DialogTitle>
          <DialogDescription>
            Pin it for quick access on your dashboard, or keep it in the library.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="title">Title</Label>
            <Input
              id="title"
              name="title"
              defaultValue={defaultTitle}
              maxLength={120}
              required
            />
          </div>
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              name="pinned"
              defaultChecked
              className="size-4 rounded border-border accent-primary"
            />
            <Pin className="size-3.5" />
            <span>Pin to dashboard</span>
          </label>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Saving…" : "Save"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
