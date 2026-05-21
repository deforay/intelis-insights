import { signOut } from "@/auth";
import { LogOut, ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ThemeToggle } from "@/components/theme-toggle";
import type { Session } from "next-auth";

async function logoutAction() {
  "use server";
  await signOut({ redirectTo: "/login" });
}

const ACCESS_LABEL: Record<string, string> = {
  district: "District",
  multi_district: "Multi-district",
  province: "Province",
  multi_province: "Multi-province",
  national: "National",
};

export function Topbar({
  title,
  session,
}: {
  title?: string;
  session: Session;
}) {
  const u = session.user;
  const scopeLabel = ACCESS_LABEL[u.accessLevel] ?? u.accessLevel;

  return (
    <header className="flex h-14 items-center gap-3 border-b px-5 bg-background/80 backdrop-blur">
      <div className="flex-1 min-w-0">
        <h1 className="text-sm font-medium text-foreground/90 truncate">
          {title ?? "New conversation"}
        </h1>
      </div>

      <Badge variant="outline" className="gap-1 font-normal">
        <ShieldCheck className="size-3" />
        {scopeLabel}
      </Badge>

      <div className="text-xs text-muted-foreground hidden lg:block">
        {u.email}
      </div>

      <ThemeToggle />

      <form action={logoutAction}>
        <Button type="submit" variant="ghost" size="icon-sm" aria-label="Sign out">
          <LogOut className="size-4" />
        </Button>
      </form>
    </header>
  );
}
