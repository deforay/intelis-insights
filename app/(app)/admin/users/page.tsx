import { redirect } from "next/navigation";
import { ShieldAlert } from "lucide-react";
import { requireAdmin } from "@/lib/auth/admin";
import { listUsers } from "@/lib/admin/users";
import { Topbar } from "@/components/app-shell/topbar";
import { Badge } from "@/components/ui/badge";
import { NewUserButton } from "@/components/admin/user-form";
import { DeleteUserButton } from "@/components/admin/delete-user-button";

export const dynamic = "force-dynamic";

export const metadata = {
  title: "Users — InteLIS Insights",
};

const ACCESS_LABEL: Record<string, string> = {
  district: "District",
  multi_district: "Multi-district",
  province: "Province",
  multi_province: "Multi-province",
  national: "National",
};

export default async function AdminUsersPage() {
  const session = await requireAdmin();
  if (!session) redirect("/chat");

  const users = await listUsers();

  return (
    <>
      <Topbar session={session} title="Users" />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-4xl w-full px-4 md:px-8 py-6">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h2 className="text-base font-semibold">User management</h2>
              <p className="text-sm text-muted-foreground">
                {users.length} {users.length === 1 ? "user" : "users"} ·
                Access scopes are enforced server-side on every query.
              </p>
            </div>
            <NewUserButton />
          </div>

          <div className="rounded-xl border bg-card overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="text-left px-4 py-2.5 font-medium">Email</th>
                  <th className="text-left px-4 py-2.5 font-medium">Role</th>
                  <th className="text-left px-4 py-2.5 font-medium">Access</th>
                  <th className="text-left px-4 py-2.5 font-medium">Scope</th>
                  <th className="text-right px-4 py-2.5 font-medium"></th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {users.map((u) => {
                  const isSelf = u.id === session.user.id;
                  return (
                    <tr key={u.id} className="hover:bg-muted/20">
                      <td className="px-4 py-2.5">
                        <div className="font-medium">{u.email}</div>
                        {u.name && (
                          <div className="text-[11px] text-muted-foreground">
                            {u.name}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-2.5">
                        {u.role === "admin" ? (
                          <Badge variant="default" className="gap-1">
                            <ShieldAlert className="size-3" />
                            Admin
                          </Badge>
                        ) : (
                          <Badge variant="secondary">User</Badge>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-foreground/80">
                        {ACCESS_LABEL[u.accessLevel] ?? u.accessLevel}
                      </td>
                      <td className="px-4 py-2.5 text-[11px] text-muted-foreground">
                        {u.accessLevel === "national" ? (
                          "all geographies"
                        ) : (
                          <ScopeCell
                            provinces={u.allowedProvinces ?? []}
                            districts={u.allowedDistricts ?? []}
                          />
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-right">
                        <DeleteUserButton
                          id={u.id}
                          email={u.email}
                          disabled={isSelf}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </main>
    </>
  );
}

function ScopeCell({
  provinces,
  districts,
}: {
  provinces: string[];
  districts: string[];
}) {
  const parts: string[] = [];
  if (provinces.length > 0) parts.push(`provinces: ${provinces.join(", ")}`);
  if (districts.length > 0) parts.push(`districts: ${districts.join(", ")}`);
  return <span>{parts.length > 0 ? parts.join(" · ") : "—"}</span>;
}
