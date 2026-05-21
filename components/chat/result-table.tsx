"use client";

import { useMemo, useState } from "react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import type { LabQueryResult } from "@/lib/graph/types";

const PAGE_SIZE = 50;

export function ResultTable({ result }: { result: LabQueryResult }) {
  const [page, setPage] = useState(0);
  const totalPages = Math.max(1, Math.ceil(result.count / PAGE_SIZE));
  const start = page * PAGE_SIZE;
  const end = start + PAGE_SIZE;

  const rows = useMemo(
    () => result.rows.slice(start, end),
    [result.rows, start, end],
  );

  if (result.count === 0) {
    return (
      <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
        Query returned 0 rows.
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="rounded-lg border overflow-hidden">
        <div className="overflow-x-auto max-h-[420px]">
          <Table>
            <TableHeader className="bg-muted/50 sticky top-0">
              <TableRow>
                {result.columns.map((c) => (
                  <TableHead
                    key={c}
                    className="text-[11px] font-medium tracking-wider"
                  >
                    {prettyHeader(c)}
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row, i) => (
                <TableRow key={start + i}>
                  {result.columns.map((c) => (
                    <TableCell key={c} className="text-xs font-mono">
                      {formatCell(row[c])}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>
      {totalPages > 1 && (
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>
            Showing {start + 1}–{Math.min(end, result.count)} of {result.count}
            {" "}rows
          </span>
          <div className="flex gap-1">
            <Button
              variant="outline"
              size="xs"
              disabled={page === 0}
              onClick={() => setPage((p) => Math.max(0, p - 1))}
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="xs"
              disabled={page >= totalPages - 1}
              onClick={() => setPage((p) => Math.min(totalPages - 1, p + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function formatCell(v: unknown): string {
  if (v === null || v === undefined) return "—";
  if (typeof v === "number") return v.toLocaleString();
  if (v instanceof Date) return v.toISOString();
  return String(v);
}

/**
 * Defensively prettify a column header in case the LLM didn't alias it
 * (e.g. `geo_name` -> `Geo Name`, `vl_test_count` -> `VL Test Count`).
 * Treats common medical-lab acronyms as such.
 */
const ACRONYMS = new Set([
  "vl",
  "hiv",
  "tb",
  "eid",
  "cd4",
  "tat",
  "art",
  "id",
  "sql",
  "url",
]);

function prettyHeader(name: string): string {
  if (/\s/.test(name)) return name; // already aliased with spaces
  return name
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((part) =>
      ACRONYMS.has(part.toLowerCase())
        ? part.toUpperCase()
        : part.charAt(0).toUpperCase() + part.slice(1).toLowerCase(),
    )
    .join(" ");
}
