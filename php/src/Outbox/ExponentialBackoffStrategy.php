<?php

declare(strict_types=1);

namespace Burrow\Sdk\Outbox;

final readonly class ExponentialBackoffStrategy implements BackoffStrategyInterface
{
    public function __construct(
        private int $baseDelaySeconds = 2,
        private float $multiplier = 2.0,
        private int $maxDelaySeconds = 300,
        private float $jitterRatio = 0.2
    ) {
    }

    public function delaySecondsForAttempt(int $attemptNumber): int
    {
        if ($attemptNumber <= 0) {
            return 0;
        }

        $delay = min((int) round($this->baseDelaySeconds * ($this->multiplier ** ($attemptNumber - 1))), $this->maxDelaySeconds);
        $ratio = min(1.0, max(0.0, $this->jitterRatio));
        $window = (int) round($delay * $ratio);
        if ($window <= 0) {
            return $delay;
        }

        $jitter = random_int(-$window, $window);
        return max(0, $delay + $jitter);
    }
}
