# Changelog

All notable changes to the Burrow PHP SDK should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.5] - 2026-03-26

### Fixed

- Craft and other CMS clients: persist `platform` from `link()`, normalize ingest payloads so POST `/api/v1/events` uses `craft-plugin` (not `wordpress-plugin`) when appropriate; added `ApplyClientPlatformDefault`, `EventSourceResolver::getDefaultEventSource`, and tests.

## [0.9.4] - 2026-03-23

### Added

- Canonical builders and contract support for `ecommerce.cart.abandoned` (lifecycle) and `ecommerce.payment.failed`, including `CanonicalEventName` allow-list entries and icon mappings (`clock-fading`, `circle-alert`).

### Changed

- README platform coverage wording for clarity.
