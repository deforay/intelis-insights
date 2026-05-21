import { redirect } from "next/navigation";
import { auth } from "@/auth";

export default async function AppShellLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await auth();
  if (!session?.user) redirect("/login");

  return (
    <div className="flex flex-1 h-screen flex-col overflow-hidden">
      {children}
    </div>
  );
}
