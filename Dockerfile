# syntax=docker/dockerfile:1

# dunglas/frankenphp ships precompiled multi-arch (amd64/arm64) PHP builds —
# unlike Railpack's mise-based installer, there's no from-source PHP compile
# on ARM64 hosts. See docs/deploy-coolify.md for why this replaced Railpack.
FROM dunglas/frankenphp:1-php8.5-bookworm AS build

RUN install-php-extensions \
    pdo_mysql \
    curl \
    mbstring \
    bcmath \
    opcache \
    zip

# The official pattern for FrankenPHP images: copy just the composer binary
# in rather than running `composer install` inside the generic `composer`
# image, so its platform-requirement check (ext-pdo_mysql, ext-curl, ...)
# runs against the same PHP build the app will actually run on.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Node isn't in the base image. Cherry-picking binaries from the official
# node image (COPY --from=node:26-bookworm /usr/local/bin/npm ...) looks
# cheaper but breaks `npm` itself — its bin script assumes files it doesn't
# find at the copied paths ("Cannot find module '../lib/cli.js'", confirmed
# against a real build). The NodeSource apt repo is the supported way to add
# Node to a Debian image and doesn't have that problem.
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_26.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# The Wayfinder Vite plugin runs `php artisan wayfinder:generate` during
# `npm run build`, which needs a bootable app — hence APP_KEY here too, same
# requirement the old Railpack setup had (see docs/deploy-coolify.md).
ARG APP_KEY
ENV APP_KEY=${APP_KEY}

RUN npm run build

FROM dunglas/frankenphp:1-php8.5-bookworm

RUN install-php-extensions \
    pdo_mysql \
    curl \
    mbstring \
    bcmath \
    opcache \
    zip

# FrankenPHP runs as PID 1 here and doesn't reap orphaned zombie processes
# (e.g. subprocesses left behind by Laravel's scheduler running every-10-
# seconds jobs). Over hours those pile up until Docker can no longer exec
# new processes into the container at all — including its own healthcheck —
# which Docker then reports as unhealthy and Traefik stops routing to.
# `tini` as the real PID 1 reaps them. See docs/deploy-coolify.md.
RUN apt-get update && apt-get install -y --no-install-recommends tini \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=build /app /app

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
