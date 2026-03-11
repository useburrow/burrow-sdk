<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class LinkedProjectDeepLink
{
    public function __construct(
        public ?string $path,
        public ?string $url
    ) {
    }
}
