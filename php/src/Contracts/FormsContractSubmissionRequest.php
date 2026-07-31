<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class FormsContractSubmissionRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public array $payload)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return FormsContractWizardHelpers::sanitizeFormsContractSubmissionPayloadForPost($this->payload);
    }

    /**
     * @return list<array{code:string,message:string,originalKey:string,sanitizedKey:string}>
     */
    public function warnings(): array
    {
        return FormsContractWizardHelpers::sanitizeFormsContractSubmissionPayload($this->payload)['warnings'];
    }
}
