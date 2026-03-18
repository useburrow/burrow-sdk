# PHP Packagist Publish Guide

This repository keeps the PHP SDK in `php/` and mirrors it to
`useburrow/sdk-php` for public Packagist publishing.

## One-time setup

1. Create GitHub repository `useburrow/sdk-php`.
2. Add repo secret `SDK_PHP_REPO_TOKEN` in this repository with a token that can
   push to `useburrow/sdk-php`.
3. Run workflow `Sync PHP SDK Repository` once (or push a change under `php/`)
   to initialize `useburrow/sdk-php:main`.
4. In Packagist, click **Submit**, paste `https://github.com/useburrow/sdk-php`,
   and enable auto-update (GitHub app or webhook).

## Ongoing release flow

1. Merge PHP changes into this repo `main`.
2. Confirm sync workflow pushed the latest split to `useburrow/sdk-php`.
3. In a local clone of `useburrow/sdk-php`, tag and push:

   ```bash
   git tag v0.9.3
   git push origin v0.9.3
   ```

   Or run:

   ```bash
   ./scripts/tag-php-sdk-release.sh useburrow/sdk-php v0.9.3 main
   ```

4. Verify the new version appears on Packagist and installs cleanly.

## Manual fallback (no GitHub Actions)

If the workflow is unavailable, run:

```bash
./scripts/publish-php-packagist.sh useburrow/sdk-php main
```

Then create and push the release tag from `useburrow/sdk-php`.
