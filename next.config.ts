import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  serverExternalPackages: [
    "@langchain/langgraph",
    "@langchain/langgraph-checkpoint-postgres",
    "@langchain/core",
    "@qdrant/js-client-rest",
    "mysql2",
    "postgres",
    "bcryptjs",
    "node-sql-parser",
  ],
};

export default nextConfig;
