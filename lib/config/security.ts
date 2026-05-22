/**
 * Security guardrails that are enforced in application code.
 *
 * These values are deliberately conservative defaults. They are not a
 * replacement for network-level rate limits, but they keep one app instance
 * from becoming an unlimited login or query fan-out point.
 */
export const SECURITY_LIMITS = {
  queryRateLimit: {
    windowMs: 60_000,
    maxRequests: 20,
  },
  loginRateLimit: {
    windowMs: 15 * 60_000,
    maxRequests: 8,
  },
  labQueryTimeoutMs: 30_000,
} as const;
