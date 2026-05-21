# syntax=docker/dockerfile:1.7
ARG NODE_VERSION=22-alpine

# ── deps: shared dependency installation ────────────────────────────────
FROM node:${NODE_VERSION} AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# ── builder: produces the Next.js standalone bundle ─────────────────────
FROM node:${NODE_VERSION} AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
RUN npm run build

# ── runner: the app server (Next.js standalone) ─────────────────────────
FROM node:${NODE_VERSION} AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000

RUN addgroup --system --gid 1001 nodejs \
 && adduser --system --uid 1001 nextjs

COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/drizzle ./drizzle

USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]

# ── init: one-shot bootstrap (migrations + corpus + seed) ───────────────
# Runs once before the app service starts. Has the full toolchain
# (drizzle-kit, tsx, source scripts) which the slim runner stage lacks.
FROM node:${NODE_VERSION} AS init
WORKDIR /app
ENV NODE_ENV=production

# Full node_modules (incl. dev deps) so drizzle-kit + tsx are available.
COPY --from=deps /app/node_modules ./node_modules
# Source artefacts needed by the orchestrator + the scripts it spawns.
COPY package.json package-lock.json tsconfig.json drizzle.config.ts ./
COPY scripts ./scripts
COPY lib ./lib
COPY drizzle ./drizzle

CMD ["npx", "tsx", "scripts/init.ts"]
