/**
 * Next.js 16 proxy (formerly middleware).
 *
 * Runs Auth.js on every matched request to populate the session for
 * server components. The matcher excludes static assets and image
 * paths so they're served without invoking the auth stack.
 */
import NextAuth from "next-auth";
import authConfig from "./auth.config";

const { auth } = NextAuth(authConfig);

export default auth;

export const config = {
  matcher: [
    "/((?!_next/static|_next/image|favicon.ico|.*\\.(?:png|jpg|jpeg|gif|webp|svg)$).*)",
  ],
};
