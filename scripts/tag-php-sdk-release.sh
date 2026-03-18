#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 2 || $# -gt 3 ]]; then
  echo "Usage: $0 <owner/repo> <version-tag> [branch]"
  echo "Example: $0 useburrow/sdk-php v0.9.3 main"
  exit 1
fi

target_repo="$1"
release_tag="$2"
target_branch="${3:-main}"
remote_url="git@github.com:${target_repo}.git"

temp_dir="$(mktemp -d)"
cleanup() {
  rm -rf "${temp_dir}"
}
trap cleanup EXIT

git clone --depth 1 --branch "${target_branch}" "${remote_url}" "${temp_dir}"

(
  cd "${temp_dir}"
  if git rev-parse "${release_tag}" >/dev/null 2>&1; then
    echo "Tag ${release_tag} already exists in ${target_repo}"
    exit 1
  fi

  git tag "${release_tag}"
  git push origin "${release_tag}"
)

echo "Pushed ${release_tag} to ${target_repo}"
