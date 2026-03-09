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

## Versioning

SemVer per package. Breaking contract changes require major bump and migration notes.
