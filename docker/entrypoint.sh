#!/usr/bin/env bash
set -euo pipefail

# Mirrors what Railpack's FrankenPHP start-container script used to do on
# every boot — see "Migrations & caching" in docs/deploy-coolify.md. If a
# migration fails, this exits non-zero and the container never becomes
# healthy, so Coolify keeps routing to the previous one.
if [ "${SKIP_MIGRATIONS:-false}" != "true" ]; then
    php artisan migrate --force
fi

php artisan storage:link
php artisan optimize:clear
php artisan optimize

exec "$@"
