import { defineConfig } from "drizzle-kit";
import "dotenv/config";

if (!process.env.APP_DB_URL) {
  throw new Error("APP_DB_URL is required to run drizzle-kit");
}

export default defineConfig({
  schema: "./lib/db/schema.ts",
  out: "./drizzle",
  dialect: "postgresql",
  dbCredentials: {
    url: process.env.APP_DB_URL,
  },
  verbose: true,
  strict: true,
});
