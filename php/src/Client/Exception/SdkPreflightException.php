<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client\Exception;

use RuntimeException;

final class SdkPreflightException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        string $message,
        public readonly string $hint
    ) {
        parent::__construct($message);
    }
}
