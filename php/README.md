# Burrow PHP SDK

Official PHP SDK for Burrow plugin integrations.

## Installation

```bash
composer require useburrow/sdk-php
```

## Requirements

- PHP 8.2+

## Quick Start

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

## Development

```bash
composer install
composer test
```

## License

MIT. See `LICENSE`.
