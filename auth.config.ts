import type { NextAuthConfig } from "next-auth";

/**
 * Edge-safe Auth.js config. Used by middleware.ts (which runs in the Edge
 * runtime and cannot import Node-only modules like `postgres` or `bcryptjs`).
 *
 * The full config (including the Credentials provider with DB lookup) lives
 * in `auth.ts` and is used by API route handlers and server components.
 */
export default {
  providers: [],
  pages: {
    signIn: "/login",
  },
  session: {
    strategy: "jwt",
  },
  callbacks: {
    authorized({ auth, request }) {
      const isLoggedIn = !!auth?.user;
      const path = request.nextUrl.pathname;

      // Public paths
      if (path === "/login" || path.startsWith("/api/auth")) {
        return true;
      }
      if (path.startsWith("/api/health")) {
        return true;
      }

      // Everything else requires auth
      return isLoggedIn;
    },
  },
} satisfies NextAuthConfig;
