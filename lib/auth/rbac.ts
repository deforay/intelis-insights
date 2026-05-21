import type { Session } from "next-auth";

export type AccessLevel =
  | "district"
  | "multi_district"
  | "province"
  | "multi_province"
  | "national";

export interface UserContext {
  userId: string;
  accessLevel: AccessLevel;
  allowedProvinces: string[];
  allowedDistricts: string[];
}

export function userContextFromSession(session: Session): UserContext {
  return {
    userId: session.user.id,
    accessLevel: session.user.accessLevel,
    allowedProvinces: session.user.allowedProvinces,
    allowedDistricts: session.user.allowedDistricts,
  };
}

export function isAdmin(session: Session | null): boolean {
  return session?.user?.role === "admin";
}

export function hasNationalAccess(ctx: UserContext): boolean {
  return ctx.accessLevel === "national";
}
