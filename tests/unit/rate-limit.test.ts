import { beforeEach, describe, expect, it } from "vitest";
import {
  checkRateLimit,
  resetRateLimitsForTests,
} from "@/lib/security/rate-limit";

describe("checkRateLimit", () => {
  beforeEach(() => {
    resetRateLimitsForTests();
  });

  it("allows requests up to the configured maximum", () => {
    expect(
      checkRateLimit("query:u1", {
        windowMs: 1_000,
        maxRequests: 2,
        now: 100,
      }).allowed,
    ).toBe(true);
    expect(
      checkRateLimit("query:u1", {
        windowMs: 1_000,
        maxRequests: 2,
        now: 200,
      }).allowed,
    ).toBe(true);
  });

  it("blocks requests over the configured maximum", () => {
    checkRateLimit("query:u1", {
      windowMs: 1_000,
      maxRequests: 1,
      now: 100,
    });
    const blocked = checkRateLimit("query:u1", {
      windowMs: 1_000,
      maxRequests: 1,
      now: 200,
    });

    expect(blocked.allowed).toBe(false);
    expect(blocked.retryAfterSeconds).toBe(1);
  });

  it("starts a fresh bucket after the window resets", () => {
    checkRateLimit("query:u1", {
      windowMs: 1_000,
      maxRequests: 1,
      now: 100,
    });

    expect(
      checkRateLimit("query:u1", {
        windowMs: 1_000,
        maxRequests: 1,
        now: 1_101,
      }).allowed,
    ).toBe(true);
  });
});
