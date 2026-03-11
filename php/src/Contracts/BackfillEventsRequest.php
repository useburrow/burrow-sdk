<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final readonly class BackfillEventsRequest
{
    /**
     * @param list<array<string,mixed>> $events Backfill events must include source-record `timestamp` per event.
     */
    public function __construct(
        public array $events,
        public BackfillWindow $backfill,
        public ?string $channel = null,
        public ?string $source = null,
        public array $routing = []
    ) {
    }

    /**
     * @return array{
     *   events:list<array<string,mixed>>,
     *   backfill:array<string,string>,
     *   channel?:string,
     *   source?:string,
     *   routing?:array<string,string>
     * }
     */
    public function toArray(): array
    {
        $payload = [
            'events' => $this->events,
            'backfill' => $this->backfill->toArray(),
        ];
        if (is_string($this->channel) && trim($this->channel) !== '') {
            $payload['channel'] = trim($this->channel);
        }
        if (is_string($this->source) && trim($this->source) !== '') {
            $payload['source'] = trim($this->source);
        }
        if ($this->routing !== []) {
            /** @var array<string,string> $routing */
            $routing = [];
            foreach ($this->routing as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }
                $trimmed = trim((string) $value);
                if ($trimmed === '') {
                    continue;
                }
                $routing[$key] = $trimmed;
            }
            if ($routing !== []) {
                $payload['routing'] = $routing;
            }
        }

        return $payload;
    }
}
