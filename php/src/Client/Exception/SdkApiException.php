<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client\Exception;

use RuntimeException;

final class SdkApiException extends RuntimeException
{
    /**
     * @param list<array<string,mixed>> $rejected
     * @param array<string,mixed>|null $apiError
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $path,
        public readonly int $status,
        public readonly string $codeName,
        string $message,
        public readonly bool $retryable,
        public readonly array $rejected = [],
        public readonly ?array $apiError = null,
        public readonly string $rawBody = '',
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}
