import { auth, signOut } from "@/auth";
import { redirect } from "next/navigation";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

export const metadata = {
  title: "Chat — InteLIS Insights",
};

async function logoutAction() {
  "use server";
  await signOut({ redirectTo: "/login" });
}

export default async function ChatPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  const u = session.user;

  return (
    <main className="flex flex-1 flex-col gap-6 p-8 max-w-4xl mx-auto w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-semibold">InteLIS Insights</h1>
          <p className="text-sm text-muted-foreground">
            Signed in as <span className="font-medium">{u.email}</span>
          </p>
        </div>
        <form action={logoutAction}>
          <Button type="submit" variant="outline" size="sm">
            Sign out
          </Button>
        </form>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Your access scope</CardTitle>
          <CardDescription>
            Queries you ask are scoped server-side to the geography you can see.
          </CardDescription>
        </CardHeader>
        <CardContent className="grid gap-2 text-sm">
          <div>
            <span className="text-muted-foreground">Role:</span>{" "}
            <span className="font-mono">{u.role}</span>
          </div>
          <div>
            <span className="text-muted-foreground">Access level:</span>{" "}
            <span className="font-mono">{u.accessLevel}</span>
          </div>
          <div>
            <span className="text-muted-foreground">Provinces:</span>{" "}
            <span className="font-mono">
              {u.allowedProvinces.length > 0
                ? u.allowedProvinces.join(", ")
                : "—"}
            </span>
          </div>
          <div>
            <span className="text-muted-foreground">Districts:</span>{" "}
            <span className="font-mono">
              {u.allowedDistricts.length > 0
                ? u.allowedDistricts.join(", ")
                : "—"}
            </span>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Ask a question</CardTitle>
          <CardDescription>
            The chat workflow is wired in Phase 5. This is a placeholder.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">
            Coming soon: natural-language query → SQL → results → chart.
          </p>
        </CardContent>
      </Card>
    </main>
  );
}
