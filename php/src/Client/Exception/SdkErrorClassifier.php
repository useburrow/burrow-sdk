<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client\Exception;

final class SdkErrorClassifier
{
    public static function isRetryableSdkError(\Throwable $error): bool
    {
        if ($error instanceof SdkApiException) {
            return $error->retryable;
        }

        if ($error instanceof UnexpectedResponseStatusException) {
            return $error->isRetryable();
        }

        return $error instanceof \RuntimeException;
    }
}
