#!/usr/bin/env bash
# Runs PHPUnit inside the wp-env `cli` container. Creates the test database on first run.
# Usage (from the repo root): npm run test:php -- [phpunit args]
set -euo pipefail
cd "$(dirname "$0")/.."
mysql --skip-ssl -h "${WORDPRESS_DB_HOST:-mysql}" -u"${WORDPRESS_DB_USER:-root}" -p"${WORDPRESS_DB_PASSWORD:-password}" \
	-e "CREATE DATABASE IF NOT EXISTS ${CJENIK_TESTS_DB_NAME:-wordpress_tests}" 2>/dev/null
[ -d vendor ] || composer install --no-interaction --quiet
exec vendor/bin/phpunit "$@"
