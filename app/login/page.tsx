import { FlaskConical } from "lucide-react";
import { signIn } from "@/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export const metadata = {
  title: "Sign in — InteLIS Insights",
};

async function loginAction(formData: FormData) {
  "use server";
  await signIn("credentials", {
    email: formData.get("email"),
    password: formData.get("password"),
    redirectTo: "/chat",
  });
}

export default function LoginPage() {
  return (
    <div className="flex flex-1 items-center justify-center p-6 bg-muted/30">
      <div className="w-full max-w-sm">
        <div className="flex items-center justify-center mb-6">
          <div className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
            <FlaskConical className="size-5" />
          </div>
        </div>
        <div className="text-center mb-6">
          <h1 className="text-xl font-semibold">InteLIS Insights</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Natural-language analytics for laboratory data
          </p>
        </div>
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <form action={loginAction} className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="email">Email</Label>
              <Input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                required
                placeholder="you@example.org"
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="password">Password</Label>
              <Input
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </div>
            <Button type="submit" className="w-full">
              Sign in
            </Button>
          </form>
        </div>
        <p className="mt-6 text-center text-[11px] text-muted-foreground">
          FOSS · AGPLv3 · Built for public-health labs
        </p>
      </div>
    </div>
  );
}
