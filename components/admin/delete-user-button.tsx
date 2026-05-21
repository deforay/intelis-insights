"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";

export function DeleteUserButton({
  id,
  email,
  disabled,
}: {
  id: string;
  email: string;
  disabled?: boolean;
}) {
  const [pending, setPending] = useState(false);
  const router = useRouter();

  const remove = async () => {
    if (!confirm(`Delete ${email}? This cannot be undone.`)) return;
    setPending(true);
    try {
      const res = await fetch(`/api/v1/admin/users/${id}`, {
        method: "DELETE",
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error ?? `HTTP ${res.status}`);
      }
      toast.success("User deleted", { description: email });
      router.refresh();
    } catch (err) {
      toast.error("Failed to delete user", {
        description: (err as Error).message,
      });
    } finally {
      setPending(false);
    }
  };

  return (
    <Button
      variant="ghost"
      size="icon-sm"
      onClick={remove}
      disabled={pending || disabled}
      aria-label={`Delete ${email}`}
    >
      <Trash2 className="size-3.5" />
    </Button>
  );
}
