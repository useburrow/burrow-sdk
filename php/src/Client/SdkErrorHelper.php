<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Client\Exception\UnexpectedResponseStatusException;
use Burrow\Sdk\Events\Exception\EventContractException;

final class SdkErrorHelper
{
    public static function isRetryableSdkError(\Throwable $throwable): bool
    {
        if ($throwable instanceof EventContractException) {
            return false;
        }

        if ($throwable instanceof UnexpectedResponseStatusException) {
            return $throwable->isRetryable();
        }

        return $throwable instanceof \RuntimeException;
    }

    /**
     * @return array{code:?string,message:?string}
     */
    public static function extractApiError(?array $body): array
    {
        $code = null;
        $message = null;
        if (is_array($body)) {
            if (isset($body['code']) && is_string($body['code'])) {
                $code = $body['code'];
            } elseif (isset($body['errorCode']) && is_string($body['errorCode'])) {
                $code = $body['errorCode'];
            }

            if (isset($body['message']) && is_string($body['message'])) {
                $message = $body['message'];
            } elseif (isset($body['error']) && is_string($body['error'])) {
                $message = $body['error'];
            }
        }

        return ['code' => $code, 'message' => $message];
    }
}
