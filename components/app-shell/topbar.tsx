import Link from "next/link";
import { signOut } from "@/auth";
import {
  LogOut,
  Plus,
  ShieldCheck,
  ScrollText,
  Users,
  FlaskConical,
} from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { ThemeToggle } from "@/components/theme-toggle";
import { SessionsMenu } from "./sessions-menu";
import { cn } from "@/lib/utils";
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
  const isAdmin = u.role === "admin";
  const scopeLabel = ACCESS_LABEL[u.accessLevel] ?? u.accessLevel;
  const initials = ((u.name ?? u.email) ?? "??").slice(0, 2).toUpperCase();

  return (
    <header className="flex h-14 items-center gap-3 border-b bg-background/70 backdrop-blur px-5">
      <Link href="/chat" className="flex items-center gap-2.5 shrink-0">
        <div className="relative">
          <div className="absolute inset-0 rounded-lg bg-primary/30 blur-md" />
          <div className="relative flex size-7 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-primary/70 text-primary-foreground">
            <FlaskConical className="size-3.5" />
          </div>
        </div>
        <span className="text-sm font-semibold tracking-tight">InteLIS</span>
      </Link>

      <div className="hidden sm:block mx-3 h-5 w-px bg-border" />

      {title && (
        <div className="hidden md:block min-w-0 flex-1">
          <span className="text-sm text-muted-foreground truncate">
            {title}
          </span>
        </div>
      )}
      {!title && <div className="flex-1" />}

      <Link
        href="/chat"
        className={cn(
          buttonVariants({ variant: "outline", size: "sm" }),
          "gap-1.5",
        )}
      >
        <Plus className="size-3.5" />
        New
      </Link>

      <SessionsMenu />

      <div className="h-5 w-px bg-border mx-1" />

      <Badge variant="outline" className="gap-1 font-normal">
        <ShieldCheck className="size-3" />
        {scopeLabel}
      </Badge>

      <ThemeToggle />

      <DropdownMenu>
        <DropdownMenuTrigger
          render={
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Account"
              className="rounded-full font-medium text-[11px] bg-muted/40 hover:bg-muted/60"
            >
              {initials}
            </Button>
          }
        />
        <DropdownMenuContent align="end" className="w-56">
          <DropdownMenuLabel className="font-normal">
            <div className="flex flex-col gap-0.5">
              <span className="font-medium">{u.name ?? u.email}</span>
              <span className="text-[11px] text-muted-foreground">
                {u.email}
              </span>
            </div>
          </DropdownMenuLabel>
          {isAdmin && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                render={
                  <Link href="/admin/users">
                    <Users className="size-3.5" />
                    Users
                  </Link>
                }
              />
              <DropdownMenuItem
                render={
                  <Link href="/admin/audit">
                    <ScrollText className="size-3.5" />
                    Audit log
                  </Link>
                }
              />
            </>
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem
            render={
              <form action={logoutAction}>
                <button type="submit" className="w-full flex items-center gap-2">
                  <LogOut className="size-3.5" />
                  Sign out
                </button>
              </form>
            }
          />
        </DropdownMenuContent>
      </DropdownMenu>
    </header>
  );
}
