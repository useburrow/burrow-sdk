<?php

declare(strict_types=1);

namespace Burrow\Sdk\Contracts;

final class FormsContractWizardHelpers
{
    public static function labelToCanonicalKey(string $label): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', trim($label)) ?? '';
        $words = preg_split('/\s+/', strtolower(trim($normalized))) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        if ($words === []) {
            return 'field';
        }

        $first = array_shift($words);
        if ($first === null) {
            return 'field';
        }

        $result = $first;
        foreach ($words as $word) {
            $result .= ucfirst($word);
        }

        return $result;
    }

    /**
     * Wizard-only canonical key helper with UX warnings. Burrow ingest uses
     * ReservedCanonicalKeys::sanitizeIncomingDimensionKey() directly.
     *
     * @return array{key:string,warnings:list<array{code:string,message:string,originalKey:string,sanitizedKey:string}>}
     */
    public static function sanitizeCanonicalKey(string $rawKey): array
    {
        $warnings = [];
        $trimmed = trim($rawKey);

        if ($trimmed === '') {
            return [
                'key' => 'field',
                'warnings' => [[
                    'code' => 'EMPTY_CANONICAL_KEY',
                    'message' => 'Canonical key was empty; defaulted to "field".',
                    'originalKey' => $rawKey,
                    'sanitizedKey' => 'field',
                ]],
            ];
        }

        $normalized = self::normalizeWizardCanonicalKey($trimmed);
        if ($normalized !== $trimmed) {
            $warnings[] = [
                'code' => 'INVALID_CANONICAL_KEY_NORMALIZED',
                'message' => sprintf('Canonical key "%s" was normalized to "%s".', $rawKey, $normalized),
                'originalKey' => $rawKey,
                'sanitizedKey' => $normalized,
            ];
        }

        $sanitized = ReservedCanonicalKeys::sanitizeIncomingDimensionKey($normalized);
        if ($sanitized !== $normalized) {
            $warnings[] = [
                'code' => 'RESERVED_CANONICAL_KEY_PREFIXED',
                'message' => sprintf(
                    'Canonical key "%s" is reserved by Burrow event feeds; prefixed with "%s" as "%s".',
                    $normalized,
                    ReservedCanonicalKeys::FEED_PREFIX,
                    $sanitized
                ),
                'originalKey' => $normalized,
                'sanitizedKey' => $sanitized,
            ];
        }

        return ['key' => $sanitized, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $mapping
     * @return array{mapping:array<string,mixed>,warnings:list<array{code:string,message:string,originalKey:string,sanitizedKey:string}>}
     */
    public static function sanitizeFieldMapping(array $mapping): array
    {
        $warnings = [];
        $sanitized = $mapping;

        if (!isset($mapping['canonicalKey']) || !is_string($mapping['canonicalKey'])) {
            return ['mapping' => $sanitized, 'warnings' => $warnings];
        }

        $result = self::sanitizeCanonicalKey($mapping['canonicalKey']);
        $sanitized['canonicalKey'] = $result['key'];
        foreach ($result['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        return ['mapping' => $sanitized, 'warnings' => $warnings];
    }

    /**
     * @param list<array<string,mixed>> $formsContracts
     * @return array{formsContracts:list<array<string,mixed>>,warnings:list<array{code:string,message:string,originalKey:string,sanitizedKey:string}>}
     */
    public static function sanitizeFormsContracts(array $formsContracts): array
    {
        $warnings = [];
        $sanitizedContracts = [];

        foreach ($formsContracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $next = $contract;
            $fieldMappings = is_array($contract['fieldMappings'] ?? null) ? $contract['fieldMappings'] : [];
            $sanitizedMappings = [];

            foreach ($fieldMappings as $mapping) {
                if (!is_array($mapping)) {
                    continue;
                }

                $result = self::sanitizeFieldMapping($mapping);
                $sanitizedMappings[] = $result['mapping'];
                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $warning;
                }
            }

            $next['fieldMappings'] = $sanitizedMappings;
            $sanitizedContracts[] = $next;
        }

        return ['formsContracts' => $sanitizedContracts, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{payload:array<string,mixed>,warnings:list<array{code:string,message:string,originalKey:string,sanitizedKey:string}>}
     */
    public static function sanitizeFormsContractSubmissionPayload(array $payload): array
    {
        $warnings = [];
        $sanitized = self::sanitizeFormsContractSubmissionPayloadForPost($payload);

        if (isset($payload['formsContracts']) && is_array($payload['formsContracts'])) {
            $wizard = self::sanitizeFormsContracts($payload['formsContracts']);
            $warnings = $wizard['warnings'];
        }

        return ['payload' => $sanitized, 'warnings' => $warnings];
    }

    /**
     * Burrow-parity contract POST sanitization (no wizard-only rewrites).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function sanitizeFormsContractSubmissionPayloadForPost(array $payload): array
    {
        if (!isset($payload['formsContracts']) || !is_array($payload['formsContracts'])) {
            return $payload;
        }

        $sanitized = $payload;
        $sanitizedContracts = [];

        foreach ($payload['formsContracts'] as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $next = $contract;
            $fieldMappings = is_array($contract['fieldMappings'] ?? null) ? $contract['fieldMappings'] : [];
            $sanitizedMappings = [];

            foreach ($fieldMappings as $mapping) {
                if (!is_array($mapping)) {
                    continue;
                }

                $nextMapping = $mapping;
                if (isset($mapping['canonicalKey']) && is_string($mapping['canonicalKey'])) {
                    $nextMapping['canonicalKey'] = ReservedCanonicalKeys::sanitizeIncomingDimensionKey($mapping['canonicalKey']);
                }
                $sanitizedMappings[] = $nextMapping;
            }

            $next['fieldMappings'] = $sanitizedMappings;
            $sanitizedContracts[] = $next;
        }

        $sanitized['formsContracts'] = $sanitizedContracts;

        return $sanitized;
    }

    private static function normalizeWizardCanonicalKey(string $value): string
    {
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $value) === 1) {
            return $value;
        }

        return self::labelToCanonicalKey($value);
    }
}
