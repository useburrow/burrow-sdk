# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added `ecommerce.cart.abandoned` lifecycle event type and canonical builders (TypeScript + PHP) for carts that go idle before checkout, with `cartTotal`, `cartItemCount`, `currency`, and `minutesSinceLastActivity` properties.
- Added `ecommerce.payment.failed` discrete event type and canonical builders (TypeScript + PHP) for failed payment attempts, with `orderId`, `cartTotal`, `currency`, `failureReason`, and `paymentMethod` properties.
- Added icon mappings for new events: `clock-fading` for cart abandoned, `circle-alert` for payment failed.
- Added PHP `CanonicalEventName` allow-list entries for both new event types.
- Updated `ecommerce.cart.recovered` documentation to note that recovery can follow either `cart.abandoned` or `checkout.abandoned` (matched by `customerToken`).

- Added TypeScript `SqlOutboxStore` with adapter-based SQL integration and tests for lifecycle state transitions.
- Added transport retry policy support in TypeScript `FetchTransport` for transient network and `5xx` failures.
- Added TypeScript CI coverage in GitHub Actions (`typecheck`, `test`, `build`) and a manual npm release workflow for package publishing.
- Added PHP forms contract ID roundtrip models and client support:
  - parsed responses for `POST /api/v1/plugin-onboarding/forms/contracts`
  - new fetch helper for `POST /api/v1/plugin-onboarding/forms/contracts/fetch`
  - local cache + reconcile utilities based on `contractsVersion`
- Added PHP persistence primitives for contract cache portability across platforms:
  - `FormsContractCacheRepositoryInterface`
  - `FormsContractCacheSerializer`
  - `InMemoryFormsContractCacheRepository`
- Added PHP onboarding link response models for:
  - project-scoped ingestion key metadata (`ingestionKey`)
  - linked project deep-link metadata (`burrowProjectPath`, `burrowProjectUrl`)
- Added `getLinkedProjectDeepLink()` helper for plugin settings UIs.
- Added TypeScript onboarding link response models for project-scoped ingestion key metadata and linked project deep-links.
- Added TypeScript `getLinkedProjectDeepLink()` helper and forms contracts fetch response parsing.
- Added PHP canonical contract hardening primitives for system/ecommerce/forms:
  - canonical event name normalizer (`CanonicalEventName`)
  - channel routing resolver/state (`ChannelRoutingResolver`, `ChannelRoutingState`)
  - authoritative system/ecommerce builders (`CanonicalEnvelopeBuilders`)
  - typed contract errors (`EventContractException`) and retryability helper (`SdkErrorHelper`)

### Changed

- Changed PHP `submitFormsContract(...)` to return typed contract response data (`projectSourceId`, `contractsVersion`, `contractMappings`, `formsContracts`) for plugin persistence/reconciliation.
- Changed PHP event envelope source resolution to capture provider origin for forms/ecommerce events (for example `gravity-forms`, `fluent-forms`, `woocommerce`) with explicit override support and platform fallback defaults.
- Migration note: `source` now captures origin provider when available, not just host platform plugin.
- Changed PHP link flow to store and use project-scoped ingestion key returned from onboarding link.
- Changed PHP event/forms calls to enforce project-scoped key guards (`projectId` required on events and must match scoped project).
- Changed TypeScript link flow to store and use project-scoped ingestion key returned from onboarding link.
- Changed TypeScript event/forms calls to enforce project-scoped key guards (`projectId` required on events and must match scoped project).
- Changed TypeScript event envelope source resolution to capture provider origin for forms/ecommerce events with explicit override support and platform fallback defaults.
- Changed PHP submit/backfill preflight to enforce canonical names and channel project source IDs before HTTP submission.

## [0.9.6] - 2026-03-27

### Added

- `ecommerce.order.placed` canonical builder (PHP + TypeScript): optional `shipping` input is emitted as **`properties.shippingTotal`**, and optional **`properties.shippingMethod`** when `shippingMethod` is set on the builder input—parity with Burrow activity UIs and the WordPress plugin.

### Changed

- PHP: `EventContractHardeningTest` asserts shipping fields on order-placed properties.

## [0.9.5] - 2026-03-26

### Fixed

- Event ingest `source` for Craft integrations: the SDK now persists `platform` from onboarding `link()`, merges it into outgoing events (when the payload does not already declare a platform), and clears a mistaken default `wordpress-plugin` so POST `/api/v1/events` emits `craft-plugin` for Craft. TypeScript and PHP implementations are aligned, with `getDefaultEventSource`, `ApplyClientPlatformDefault` / `applyClientPlatformDefault`, and coverage tests.

## [0.2.0] - 2026-03-09

### Added

- Added robust transport and client error model with explicit exceptions for transport failures, invalid JSON, and unexpected HTTP response statuses.
- Added configurable retry policy support in HTTP transport for transient failures (network and `5xx` responses).
- Added typed HTTP response object and accepted runtime status handling for `200` and `207`.
- Added SQL-backed outbox implementation (`SqlOutboxStore`) with durable lifecycle fields: status, attempt count, last error, created/updated timestamps, next attempt scheduling, and sent timestamp.
- Added outbox worker processing service with retry/fail state transitions and exponential backoff strategy helpers.
- Added canonical Burrow contract fixtures for system and ecommerce events:
  - `spec/contracts/event-system-stack-snapshot.json`
  - `spec/contracts/event-system-heartbeat-ping.json`
  - `spec/contracts/event-ecommerce-order-placed.json`
  - `spec/contracts/event-ecommerce-item-purchased.json`
- Added documentation for SQL outbox schema and error handling:
  - `docs/outbox-schema.md`
  - `docs/error-handling.md`

### Changed

- Expanded README usage coverage for onboarding discover/link/contracts, event publishing, and durable outbox worker run loops.
- Expanded test coverage for:
  - endpoint/header behavior in `BurrowClient`
  - event envelope required/default behavior
  - outbox status transitions and worker success/retry/fail paths
  - fixture-backed contract envelope shape and taxonomy validation

### Validation

- PHPUnit test suite passes: `18 tests`, `133 assertions`.

[Unreleased]: https://github.com/useburrow/burrow-sdk/compare/0.9.6...HEAD
[0.9.6]: https://github.com/useburrow/burrow-sdk/releases/tag/0.9.6
[0.9.5]: https://github.com/useburrow/burrow-sdk/releases/tag/0.9.5
[0.2.0]: https://github.com/useburrow/burrow-sdk/releases/tag/0.2.0
