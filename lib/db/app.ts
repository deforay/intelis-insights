import { drizzle } from "drizzle-orm/postgres-js";
import postgres from "postgres";
import { env } from "@/lib/config/env";
import * as schema from "./schema";

declare global {
   
  var __appDbClient: ReturnType<typeof postgres> | undefined;
}

const client =
  globalThis.__appDbClient ??
  postgres(env.APP_DB_URL, {
    max: 10,
    idle_timeout: 30,
    connect_timeout: 10,
  });

if (env.NODE_ENV !== "production") {
  globalThis.__appDbClient = client;
}

export const db = drizzle(client, { schema });
export { schema };
