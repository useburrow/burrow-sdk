<?php

declare(strict_types=1);

namespace Burrow\Sdk\Client;

final class BurrowClientState
{
    /**
     * @param list<array<string,mixed>> $contractMappings
     */
    public function __construct(
        public ?string $ingestionKey = null,
        public ?string $projectId = null,
        public ?string $formsProjectSourceId = null,
        public ?string $contractsVersion = null,
        public array $contractMappings = [],
        public ?string $clientId = null
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $contractMappings = [];
        if (isset($payload['contractMappings']) && is_array($payload['contractMappings'])) {
            /** @var list<array<string,mixed>> $contractMappings */
            $contractMappings = array_values(array_filter(
                $payload['contractMappings'],
                static fn (mixed $row): bool => is_array($row)
            ));
        }

        return new self(
            ingestionKey: self::readString($payload['ingestionKey'] ?? null),
            projectId: self::readString($payload['projectId'] ?? null),
            formsProjectSourceId: self::readString($payload['formsProjectSourceId'] ?? null),
            contractsVersion: self::readString($payload['contractsVersion'] ?? null),
            contractMappings: $contractMappings,
            clientId: self::readString($payload['clientId'] ?? null)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'ingestionKey' => $this->ingestionKey,
            'projectId' => $this->projectId,
            'formsProjectSourceId' => $this->formsProjectSourceId,
            'contractsVersion' => $this->contractsVersion,
            'contractMappings' => $this->contractMappings,
            'clientId' => $this->clientId,
        ];
    }

    private static function readString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
