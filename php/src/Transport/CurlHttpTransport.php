<?php

declare(strict_types=1);

namespace Burrow\Sdk\Transport;

use Burrow\Sdk\Transport\Exception\InvalidJsonException;
use Burrow\Sdk\Transport\Exception\TransportFailureException;
use JsonException;

final class CurlHttpTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy()
    )
    {
    }

    public function post(string $url, array $headers, array $payload): HttpResponse
    {
        for ($attempt = 1; $attempt <= $this->retryPolicy->maxAttempts; $attempt++) {
            try {
                $response = $this->postOnce($url, $headers, $payload);
            } catch (TransportFailureException $exception) {
                if (!$this->retryPolicy->shouldRetryTransportFailure($attempt)) {
                    throw $exception;
                }

                $this->sleepForRetry($attempt);
                continue;
            }

            if ($this->retryPolicy->shouldRetryStatus($response->status, $attempt)) {
                $this->sleepForRetry($attempt);
                continue;
            }

            return $response;
        }

        throw new TransportFailureException('Request failed after exhausting retry attempts.');
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     */
    private function postOnce(string $url, array $headers, array $payload): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TransportFailureException('Failed to initialize cURL.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidJsonException('Failed to encode JSON request payload.', 0, $exception);
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Content-Type: application/json';

        /** @var array<string,string> $responseHeaders */
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return strlen($headerLine);
            },
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportFailureException('HTTP request failed: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === '') {
            return new HttpResponse($status, null, $raw, $responseHeaders);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidJsonException('Failed to decode JSON response body.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidJsonException('Response JSON must decode into an object.');
        }

        return new HttpResponse($status, $decoded, $raw, $responseHeaders);
    }

    private function sleepForRetry(int $attempt): void
    {
        $delayMilliseconds = $this->retryPolicy->delayMillisecondsForAttempt($attempt);
        if ($delayMilliseconds <= 0) {
            return;
        }

        usleep($delayMilliseconds * 1_000);
    }
}
