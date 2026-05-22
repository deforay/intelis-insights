import NextAuth from "next-auth";
import Credentials from "next-auth/providers/credentials";
import { compare } from "bcryptjs";
import { eq } from "drizzle-orm";
import { z } from "zod";
import authConfig from "./auth.config";
import { db } from "@/lib/db/app";
import { users } from "@/lib/db/schema";
import { SECURITY_LIMITS } from "@/lib/config/security";
import { checkRateLimit } from "@/lib/security/rate-limit";

const CredentialsSchema = z.object({
  email: z.email(),
  password: z.string().min(1),
});

export const { handlers, signIn, signOut, auth } = NextAuth({
  ...authConfig,
  providers: [
    Credentials({
      credentials: {
        email: { label: "Email", type: "email" },
        password: { label: "Password", type: "password" },
      },
      async authorize(credentials, request) {
        const parsed = CredentialsSchema.safeParse(credentials);
        if (!parsed.success) return null;

        const emailKey = parsed.data.email.toLowerCase();
        const ip =
          request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ??
          request.headers.get("x-real-ip") ??
          "unknown";
        const rateLimit = checkRateLimit(
          `login:${ip}:${emailKey}`,
          SECURITY_LIMITS.loginRateLimit,
        );
        if (!rateLimit.allowed) return null;

        const [user] = await db
          .select()
          .from(users)
          .where(eq(users.email, parsed.data.email))
          .limit(1);
        if (!user) return null;

        const ok = await compare(parsed.data.password, user.passwordHash);
        if (!ok) return null;

        return {
          id: user.id,
          email: user.email,
          name: user.name ?? user.email,
          role: user.role,
          accessLevel: user.accessLevel,
          allowedProvinces: user.allowedProvinces,
          allowedDistricts: user.allowedDistricts,
        };
      },
    }),
  ],
  callbacks: {
    ...authConfig.callbacks,
    async jwt({ token, user }) {
      if (user) {
        token.id = user.id as string;
        token.role = user.role;
        token.accessLevel = user.accessLevel;
        token.allowedProvinces = user.allowedProvinces;
        token.allowedDistricts = user.allowedDistricts;
      }
      return token;
    },
    async session({ session, token }) {
      if (session.user) {
        session.user.id = token.id;
        session.user.role = token.role;
        session.user.accessLevel = token.accessLevel;
        session.user.allowedProvinces = token.allowedProvinces;
        session.user.allowedDistricts = token.allowedDistricts;
      }
      return session;
    },
  },
});
