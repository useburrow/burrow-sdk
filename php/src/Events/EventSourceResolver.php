<?php

declare(strict_types=1);

namespace Burrow\Sdk\Events;

final class EventSourceResolver
{
    /**
     * @var array<string,string>
     */
    private const FORMS_PROVIDER_MAP = [
        'gravity-forms' => 'gravity-forms',
        'gravityforms' => 'gravity-forms',
        'fluent-forms' => 'fluent-forms',
        'fluentforms' => 'fluent-forms',
        'contact-form-7' => 'contact-form-7',
        'contactform7' => 'contact-form-7',
        'cf7' => 'contact-form-7',
        'ninja-forms' => 'ninja-forms',
        'ninjaforms' => 'ninja-forms',
        'freeform' => 'freeform',
        'formie' => 'formie',
    ];

    /**
     * @var array<string,string>
     */
    private const ECOMMERCE_PROVIDER_MAP = [
        'woocommerce' => 'woocommerce',
        'woo-commerce' => 'woocommerce',
        'craft-commerce' => 'craft-commerce',
        'craftcommerce' => 'craft-commerce',
    ];

    /**
     * @param array<string,mixed> $event
     */
    public static function resolveSourceForEvent(array $event): string
    {
        $channel = strtolower(trim((string) ($event['channel'] ?? '')));
        $provider = self::extractProviderHint($event);

        if ($channel === 'forms' && $provider !== null) {
            $resolved = self::resolveFormsProvider($provider);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($channel === 'ecommerce' && $provider !== null) {
            $resolved = self::resolveEcommerceProvider($provider);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return self::resolvePlatformFallback($event);
    }

    private static function resolveFormsProvider(string $provider): ?string
    {
        $key = self::normalizeProviderKey($provider);
        return self::FORMS_PROVIDER_MAP[$key] ?? null;
    }

    private static function resolveEcommerceProvider(string $provider): ?string
    {
        $key = self::normalizeProviderKey($provider);
        return self::ECOMMERCE_PROVIDER_MAP[$key] ?? null;
    }

    private static function normalizeProviderKey(string $provider): string
    {
        return strtolower(trim($provider));
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function resolvePlatformFallback(array $event): string
    {
        $platform = self::extractPlatformHint($event);
        if ($platform === 'craft') {
            return 'craft-plugin';
        }

        return 'wordpress-plugin';
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function extractProviderHint(array $event): ?string
    {
        $keys = [
            'provider',
            'providerSlug',
            'integration',
            'integrationSlug',
            'sourcePlugin',
            'plugin',
            'adapter',
            'formProvider',
            'ecommerceProvider',
        ];

        foreach ($keys as $key) {
            $value = self::readString($event, $key);
            if ($value !== null) {
                return $value;
            }

            $properties = $event['properties'] ?? null;
            if (is_array($properties)) {
                $propertyValue = self::readString($properties, $key);
                if ($propertyValue !== null) {
                    return $propertyValue;
                }
            }

            $tags = $event['tags'] ?? null;
            if (is_array($tags)) {
                $tagValue = self::readString($tags, $key);
                if ($tagValue !== null) {
                    return $tagValue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $event
     */
    private static function extractPlatformHint(array $event): ?string
    {
        $platform = self::readString($event, 'platform');
        if ($platform !== null) {
            return strtolower($platform);
        }

        $properties = $event['properties'] ?? null;
        if (is_array($properties)) {
            $platform = self::readString($properties, 'platform');
            if ($platform !== null) {
                return strtolower($platform);
            }
        }

        $tags = $event['tags'] ?? null;
        if (is_array($tags)) {
            $platform = self::readString($tags, 'platform');
            if ($platform !== null) {
                return strtolower($platform);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function readString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
