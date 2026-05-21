export function UserBubble({ content }: { content: string }) {
  return (
    <div className="relative">
      <div className="text-[10px] uppercase tracking-wider text-muted-foreground/60 mb-2">
        You asked
      </div>
      <h2 className="text-2xl md:text-3xl font-semibold tracking-tight leading-tight bg-gradient-to-b from-foreground to-foreground/80 bg-clip-text text-transparent">
        {content}
      </h2>
    </div>
  );
}
