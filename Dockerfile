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

# Node isn't in the base image — copy the prebuilt binaries from the
# official Node image instead of an apt/NodeSource setup, cheaper and no
# extra package sources to maintain.
COPY --from=node:26-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26-bookworm /usr/local/bin/npm /usr/local/bin/npm
COPY --from=node:26-bookworm /usr/local/bin/npx /usr/local/bin/npx
COPY --from=node:26-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules

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

WORKDIR /app

COPY --from=build /app /app

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
