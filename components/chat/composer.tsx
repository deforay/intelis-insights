"use client";

import { useEffect, useRef } from "react";
import { ArrowUp, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";

export function Composer({
  value,
  onChange,
  onSubmit,
  isStreaming,
  placeholder,
}: {
  value: string;
  onChange: (v: string) => void;
  onSubmit: () => void;
  isStreaming: boolean;
  placeholder?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (!ref.current) return;
    ref.current.style.height = "auto";
    ref.current.style.height = `${Math.min(ref.current.scrollHeight, 200)}px`;
  }, [value]);

  useEffect(() => {
    if (!isStreaming) ref.current?.focus();
  }, [isStreaming]);

  const handleKey = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && (e.metaKey || e.ctrlKey || !e.shiftKey)) {
      e.preventDefault();
      if (!isStreaming && value.trim()) onSubmit();
    }
  };

  const canSubmit = !isStreaming && value.trim().length > 0;

  return (
    <div className="relative border rounded-2xl bg-card/80 backdrop-blur focus-within:border-primary/40 focus-within:brand-glow transition-all">
      <Textarea
        ref={ref}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onKeyDown={handleKey}
        placeholder={placeholder ?? "Ask anything about your lab data…"}
        rows={1}
        className="resize-none border-0 bg-transparent shadow-none focus-visible:ring-0 px-4 py-3.5 pr-14 text-sm min-h-[52px] max-h-[200px]"
      />
      <div className="absolute right-2 bottom-2">
        <Button
          size="icon-sm"
          onClick={onSubmit}
          disabled={!canSubmit}
          aria-label="Send"
        >
          {isStreaming ? (
            <Loader2 className="size-4 animate-spin" />
          ) : (
            <ArrowUp className="size-4" />
          )}
        </Button>
      </div>
    </div>
  );
}
