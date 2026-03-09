# Burrow SDKs

Shared SDKs for Burrow plugin integrations (Craft CMS, WordPress, and future platforms).

## Current Scope

- PHP-first implementation for Craft and WordPress plugins
- Shared onboarding and event contract fixtures
- Durable outbox + retry primitives

## Repository Layout

```text
php/
  src/
  tests/
spec/
  contracts/
docs/
.github/workflows/
```

## Phase 1 Delivery

1. Transport client (API key auth + endpoint wrappers)
2. Contract models and validation
3. Event envelope builders
4. Outbox interfaces and SQL implementation

## Local Dev (PHP package)

```bash
cd php
composer install
composer test
```

## PHP SDK Usage

### Initialize Client

```php
use Burrow\Sdk\Client\BurrowClient;
use Burrow\Sdk\Transport\CurlHttpTransport;
use Burrow\Sdk\Transport\RetryPolicy;

$transport = new CurlHttpTransport(
    timeoutSeconds: 8,
    retryPolicy: new RetryPolicy(maxAttempts: 3, baseDelayMilliseconds: 200)
);

$client = new BurrowClient(
    baseUrl: 'https://api.useburrow.com',
    apiKey: 'your-plugin-api-key',
    transport: $transport
);
```

### Onboarding: Discover

```php
use Burrow\Sdk\Contracts\OnboardingDiscoveryRequest;

$response = $client->discover(new OnboardingDiscoveryRequest(
    site: ['url' => 'https://example.com', 'cmsVersion' => '6.0.0'],
    capabilities: ['forms' => ['freeform']]
));
```

### Onboarding: Link

```php
use Burrow\Sdk\Contracts\OnboardingLinkRequest;

$response = $client->link(new OnboardingLinkRequest(
    site: ['url' => 'https://example.com'],
    selection: ['organizationId' => 'org_123', 'projectId' => 'prj_123']
));
```

### Onboarding: Submit Contracts

```php
use Burrow\Sdk\Contracts\FormsContractSubmissionRequest;

$payload = json_decode(file_get_contents(__DIR__ . '/../spec/contracts/forms-contracts.request.json'), true);
$response = $client->submitFormsContract(new FormsContractSubmissionRequest($payload));
```

### Publish Event

```php
use Burrow\Sdk\Events\EventEnvelopeBuilder;

$event = EventEnvelopeBuilder::build([
    'organizationId' => 'org_123',
    'clientId' => 'cli_123',
    'channel' => 'forms',
    'event' => 'forms.submission.received',
    'timestamp' => gmdate('c'),
    'properties' => ['submissionId' => 'sub_123'],
    'tags' => ['formId' => 'contact'],
]);

$response = $client->publishEvent($event);
```

### Durable Outbox + Worker Loop

```php
use Burrow\Sdk\Outbox\OutboxWorker;
use Burrow\Sdk\Outbox\SqlOutboxStore;

$pdo = new PDO($dsn, $user, $password);
$store = new SqlOutboxStore($pdo);

$store->enqueue(
    eventKey: 'forms:contact:sub_123',
    payload: $event
);

$worker = new OutboxWorker($store, $client, maxAttempts: 5);

while (true) {
    $result = $worker->runOnce(limit: 100);
    if ($result->processedCount === 0) {
        sleep(2);
    }
}
```

## Versioning

SemVer per package. Breaking contract changes require major bump and migration notes.
