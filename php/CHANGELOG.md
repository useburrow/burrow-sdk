# Changelog

All notable changes to the Burrow PHP SDK should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.7] - 2026-03-27

### Changed

- `buildEcommerceOrderPlacedEvent` now accepts `shippingTotal` as the canonical input key for order-level shipping cost. Legacy `shipping` input key is still accepted as a deprecated backward-compatible alias. Output always uses `properties.shippingTotal`.
- Migration note: update builder input from `'shipping' => $amount` to `'shippingTotal' => $amount`. The old key continues to work but will be removed in a future major version.

## [0.9.6] - 2026-03-27

### Added

- `CanonicalEnvelopeBuilders::buildEcommerceOrderPlacedEvent`: optional numeric `shippingTotal` (or deprecated alias `shipping`) maps to **`properties.shippingTotal`**, optional string `shippingMethod` to **`properties.shippingMethod`**.

### Changed

- `EventContractHardeningTest` covers shipping fields on `ecommerce.order.placed` properties.

## [0.9.5] - 2026-03-26

### Fixed

- Craft and other CMS clients: persist `platform` from `link()`, normalize ingest payloads so POST `/api/v1/events` uses `craft-plugin` (not `wordpress-plugin`) when appropriate; added `ApplyClientPlatformDefault`, `EventSourceResolver::getDefaultEventSource`, and tests.

## [0.9.4] - 2026-03-23

### Added

- Canonical builders and contract support for `ecommerce.cart.abandoned` (lifecycle) and `ecommerce.payment.failed`, including `CanonicalEventName` allow-list entries and icon mappings (`clock-fading`, `circle-alert`).

### Changed

- README platform coverage wording for clarity.
