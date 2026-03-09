export interface RetryPolicy {
  maxAttempts: number;
  shouldRetryStatus(status: number, attemptNumber: number): boolean;
  shouldRetryTransportFailure(attemptNumber: number): boolean;
  delayMsForAttempt(attemptNumber: number): number;
}

export interface RetryPolicyOptions {
  maxAttempts?: number;
  baseDelayMs?: number;
  multiplier?: number;
  maxDelayMs?: number;
}

export function createDefaultRetryPolicy(options: RetryPolicyOptions = {}): RetryPolicy {
  const maxAttempts = options.maxAttempts ?? 3;
  const baseDelayMs = options.baseDelayMs ?? 150;
  const multiplier = options.multiplier ?? 2;
  const maxDelayMs = options.maxDelayMs ?? 2_000;

  return {
    maxAttempts,
    shouldRetryStatus(status: number, attemptNumber: number): boolean {
      return status >= 500 && status <= 599 && attemptNumber < maxAttempts;
    },
    shouldRetryTransportFailure(attemptNumber: number): boolean {
      return attemptNumber < maxAttempts;
    },
    delayMsForAttempt(attemptNumber: number): number {
      if (attemptNumber <= 0) {
        return 0;
      }
      const delay = Math.round(baseDelayMs * multiplier ** (attemptNumber - 1));
      return Math.min(delay, maxDelayMs);
    },
  };
}
