<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;

interface BurrowClientInterface
{
    /**
     * @return array{status:int,body:array<string,mixed>|null,raw:string}
     */
    public function discover(OnboardingDiscoveryRequest $request): array;

    /**
     * @return array{status:int,body:array<string,mixed>|null,raw:string}
     */
    public function link(OnboardingLinkRequest $request): array;

    /**
     * @return array{status:int,body:array<string,mixed>|null,raw:string}
     */
    public function submitFormsContract(FormsContractSubmissionRequest $request): array;

    /**
     * @param array<string,mixed> $event
     * @return array{status:int,body:array<string,mixed>|null,raw:string}
     */
    public function publishEvent(array $event): array;
}
