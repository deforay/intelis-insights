export function UserBubble({ content }: { content: string }) {
  return (
    <div className="flex justify-end">
      <div className="max-w-[85%] rounded-2xl rounded-br-sm bg-gradient-to-br from-primary to-primary/85 px-4 py-2.5 text-sm text-primary-foreground whitespace-pre-wrap brand-glow">
        {content}
      </div>
    </div>
  );
}
