import type { DefaultSession } from "next-auth";

declare module "next-auth" {
  interface Session {
    user: {
      id: string;
      role: "admin" | "user";
      accessLevel:
        | "district"
        | "multi_district"
        | "province"
        | "multi_province"
        | "national";
      allowedProvinces: string[];
      allowedDistricts: string[];
    } & DefaultSession["user"];
  }

  interface User {
    id?: string;
    role: "admin" | "user";
    accessLevel:
      | "district"
      | "multi_district"
      | "province"
      | "multi_province"
      | "national";
    allowedProvinces: string[];
    allowedDistricts: string[];
  }
}

declare module "next-auth/jwt" {
  interface JWT {
    id: string;
    role: "admin" | "user";
    accessLevel:
      | "district"
      | "multi_district"
      | "province"
      | "multi_province"
      | "national";
    allowedProvinces: string[];
    allowedDistricts: string[];
  }
}

declare module "@auth/core/jwt" {
  interface JWT {
    id: string;
    role: "admin" | "user";
    accessLevel:
      | "district"
      | "multi_district"
      | "province"
      | "multi_province"
      | "national";
    allowedProvinces: string[];
    allowedDistricts: string[];
  }
}
