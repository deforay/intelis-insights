import { redirect } from "next/navigation";
import { auth } from "@/auth";
import { Sidebar } from "@/components/app-shell/sidebar";

export default async function AppShellLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  return (
    <div className="flex flex-1 h-screen overflow-hidden">
      <Sidebar />
      <div className="flex flex-1 flex-col min-w-0">{children}</div>
    </div>
  );
}
