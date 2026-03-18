# Release Process

1. Update `spec/contracts` fixtures if contract behavior changed.
2. Run tests in `php/`.
3. Update `php/CHANGELOG.md` with release notes and migration notes for breaking changes.
4. Sync `php/` to `useburrow/sdk-php` (workflow `Sync PHP SDK Repository` or `./scripts/publish-php-packagist.sh useburrow/sdk-php main`).
5. Tag release in `useburrow/sdk-php` per SemVer (for example `v0.9.3`) and push tag.
6. Confirm Packagist ingests the tag and package metadata.

See `docs/php-packagist-publish.md` for full setup and publish details.
