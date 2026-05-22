interface RateLimitOptions {
  windowMs: number;
  maxRequests: number;
  now?: number;
}

interface Bucket {
  count: number;
  resetAt: number;
}

export interface RateLimitDecision {
  allowed: boolean;
  remaining: number;
  retryAfterSeconds: number;
  resetAt: number;
}

declare global {
  var __rateLimitBuckets: Map<string, Bucket> | undefined;
}

const buckets = globalThis.__rateLimitBuckets ?? new Map<string, Bucket>();
globalThis.__rateLimitBuckets = buckets;

export function checkRateLimit(
  key: string,
  opts: RateLimitOptions,
): RateLimitDecision {
  const now = opts.now ?? Date.now();
  pruneExpired(now);

  const existing = buckets.get(key);
  const bucket =
    existing && existing.resetAt > now
      ? existing
      : { count: 0, resetAt: now + opts.windowMs };

  bucket.count += 1;
  buckets.set(key, bucket);

  const allowed = bucket.count <= opts.maxRequests;
  return {
    allowed,
    remaining: Math.max(0, opts.maxRequests - bucket.count),
    retryAfterSeconds: allowed
      ? 0
      : Math.max(1, Math.ceil((bucket.resetAt - now) / 1000)),
    resetAt: bucket.resetAt,
  };
}

export function resetRateLimitsForTests(): void {
  buckets.clear();
}

function pruneExpired(now: number): void {
  if (buckets.size < 1_000) return;
  for (const [key, bucket] of buckets) {
    if (bucket.resetAt <= now) buckets.delete(key);
  }
}
