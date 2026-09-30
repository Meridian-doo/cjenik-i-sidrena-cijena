#!/usr/bin/env bash
# Runs PHPStan inside the wp-env `cli` container.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -d vendor ] || composer install --no-interaction --quiet
exec vendor/bin/phpstan analyse --memory-limit=1G --no-progress "$@"
