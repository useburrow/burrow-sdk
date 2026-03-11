<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events\Exception;

use InvalidArgumentException;

final class EventContractException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $remediation = null
    ) {
        parent::__construct($message);
    }
}
