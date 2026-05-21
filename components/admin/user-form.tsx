"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Plus } from "lucide-react";

const ACCESS_LEVELS = [
  { value: "district", label: "District" },
  { value: "multi_district", label: "Multi-district" },
  { value: "province", label: "Province" },
  { value: "multi_province", label: "Multi-province" },
  { value: "national", label: "National" },
] as const;

const ROLES = [
  { value: "user", label: "User" },
  { value: "admin", label: "Admin" },
] as const;

export function NewUserButton() {
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const router = useRouter();

  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    const body = {
      email: String(form.get("email")),
      password: String(form.get("password")),
      name: String(form.get("name")) || null,
      role: String(form.get("role")) as "admin" | "user",
      accessLevel: String(form.get("accessLevel")),
      allowedProvinces: splitCsv(String(form.get("allowedProvinces"))),
      allowedDistricts: splitCsv(String(form.get("allowedDistricts"))),
    };
    setSubmitting(true);
    try {
      const res = await fetch("/api/v1/admin/users", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error ?? `HTTP ${res.status}`);
      }
      toast.success("User created", { description: body.email });
      setOpen(false);
      router.refresh();
    } catch (err) {
      toast.error("Failed to create user", {
        description: (err as Error).message,
      });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger
        render={
          <Button>
            <Plus className="size-4" />
            New user
          </Button>
        }
      />
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Create user</DialogTitle>
          <DialogDescription>
            Set the access scope to control which data this user can query.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="flex flex-col gap-4">
          <Field name="email" label="Email" type="email" required />
          <Field
            name="password"
            label="Password"
            type="password"
            required
            minLength={8}
          />
          <Field name="name" label="Display name" />
          <SelectField name="role" label="Role" options={ROLES} defaultValue="user" />
          <SelectField
            name="accessLevel"
            label="Access level"
            options={ACCESS_LEVELS}
            defaultValue="district"
          />
          <Field
            name="allowedProvinces"
            label="Allowed province IDs (comma-separated)"
            placeholder="e.g. 12, 34"
          />
          <Field
            name="allowedDistricts"
            label="Allowed district IDs (comma-separated)"
            placeholder="e.g. 101, 102"
          />
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? "Creating…" : "Create"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function Field({
  name,
  label,
  ...rest
}: {
  name: string;
  label: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      <Input id={name} name={name} {...rest} />
    </div>
  );
}

function SelectField({
  name,
  label,
  options,
  defaultValue,
}: {
  name: string;
  label: string;
  options: readonly { value: string; label: string }[];
  defaultValue?: string;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={name}>{label}</Label>
      <select
        id={name}
        name={name}
        defaultValue={defaultValue}
        className="h-8 rounded-lg border bg-background px-2.5 text-sm focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 outline-none"
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

function splitCsv(s: string): string[] {
  return s
    .split(",")
    .map((v) => v.trim())
    .filter(Boolean);
}
