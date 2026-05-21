import path from "node:path";
import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    include: [
      "tests/unit/**/*.test.ts",
      "tests/integration/**/*.test.ts",
      "tests/eval/**/*.test.ts",
    ],
    environment: "node",
    // Default to placeholder env so module-load env validation doesn't
    // fail on unit tests. Eval tests override this by also setting
    // EVAL=1 and supplying real env vars at the command line.
    env: {
      SKIP_ENV_VALIDATION: process.env.SKIP_ENV_VALIDATION ?? "1",
    },
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "."),
    },
  },
});
