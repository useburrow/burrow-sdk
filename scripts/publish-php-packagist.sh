#!/usr/bin/env bash

set -euo pipefail

# Mirrors php/ to sdk-php repository root using git subtree split.
if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Usage: $0 <owner/repo> [branch]"
  echo "Example: $0 useburrow/sdk-php main"
  exit 1
fi

target_repo="$1"
target_branch="${2:-main}"

if ! command -v git >/dev/null 2>&1; then
  echo "git is required"
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Working tree must be clean before publishing."
  exit 1
fi

split_sha="$(git subtree split --prefix=php HEAD)"
remote_url="git@github.com:${target_repo}.git"
temp_remote="php-packagist-temp"

cleanup() {
  if git remote get-url "${temp_remote}" >/dev/null 2>&1; then
    git remote remove "${temp_remote}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

git remote add "${temp_remote}" "${remote_url}"
git push "${temp_remote}" "${split_sha}:refs/heads/${target_branch}"

echo "Mirrored php/ (${split_sha}) to ${target_repo}:${target_branch}"
echo "To release, tag in the target repo and push the tag."
