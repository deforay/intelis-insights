import { auth } from "@/auth";
import { redirect } from "next/navigation";
import { Topbar } from "@/components/app-shell/topbar";
import { ChatClient } from "@/components/chat/chat-client";

export const metadata = {
  title: "New chat — InteLIS Insights",
};

export default async function NewChatPage() {
  const session = await auth();
  if (!session?.user) redirect("/login");

  return (
    <>
      <Topbar session={session} />
      <ChatClient />
    </>
  );
}
