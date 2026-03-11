<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

use Burrow\Sdk\Contracts\BackfillEventsRequest;
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;
use Burrow\Sdk\Contracts\FormsContractsResponse;
use Burrow\Sdk\Contracts\LinkedProjectDeepLink;
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;
use Burrow\Sdk\Contracts\OnboardingLinkRequest;
use Burrow\Sdk\Contracts\OnboardingLinkResponse;
use Burrow\Sdk\Transport\HttpResponse;

interface BurrowClientInterface
{
    /**
     */
    public function discover(OnboardingDiscoveryRequest $request): HttpResponse;

    /**
     */
    public function link(OnboardingLinkRequest $request): OnboardingLinkResponse;

    /**
     */
    public function submitFormsContract(FormsContractSubmissionRequest $request): FormsContractsResponse;

    public function fetchFormsContracts(string $projectId, string $platform): FormsContractsResponse;

    public function getLinkedProjectDeepLink(): ?LinkedProjectDeepLink;

    public function getState(): BurrowClientState;

    public function getProjectId(): ?string;

    public function getProjectSourceId(?string $channel = 'forms'): ?string;

    /**
     * @return array{projectId:string,projectSourceId:string,clientId?:string}
     */
    public function getBackfillRouting(string $channel): array;

    /**
     * @param array<string,mixed> $event
     */
    public function publishEvent(array $event): HttpResponse;

    /**
     * @param callable(BackfillProgressUpdate):void|null $progressCallback
     */
    public function backfillEvents(
        BackfillEventsRequest $request,
        ?BackfillOptions $options = null,
        ?callable $progressCallback = null
    ): BackfillEventsResult;
}
