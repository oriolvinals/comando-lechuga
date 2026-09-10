# Deploying to Coolify

No Docker involved — Coolify builds this with **Railpack** directly from
`railpack.json` at the repo root (Railpack auto-detects the PHP/Node
toolchain from `composer.json` / `package.json`; the config file only pins
the PHP/Node versions and tightens the composer install). Railpack is
Railway's build system, the modern replacement for Nixpacks — Coolify
supports it as an alternative build pack (marked **Beta** there as of this
writing).

Railpack's PHP provider serves the app with **FrankenPHP** (a single Caddy +
PHP binary), not nginx + php-fpm — there's no supervisor config to write by
hand here, unlike the old Nixpacks setup. FrankenPHP auto-detects Laravel
(via the `artisan` file), points its document root at `public/`, and on
every container start runs `php artisan migrate --force`, then
`storage:link`, `optimize:clear`, `optimize` before serving traffic — see
[Migrations & caching](#migrations--caching) below, since this changes how
deploys are gated compared to the old Nixpacks setup.

## 1. App resource settings

- **Build Pack**: Railpack
- **Port**: whatever Coolify injects via `$PORT` (FrankenPHP's Caddy listens
  on it automatically) — also set as the container's exposed port
- **Health check path**: `/up` (Laravel's default health-check route,
  already wired up in `bootstrap/app.php`)

## 2. Database

Create a **MySQL** resource in Coolify (you said you'd add this yourself —
8.0+ or MariaDB 10.11+ both work, `config/database.php` supports either).
Its host is reachable from the app by service name over Coolify's private
network, not `localhost`.

## 3. Environment variables

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | generate once with `php artisan key:generate --show`, paste the result — **never regenerate on redeploy**, it invalidates every session/cookie. Mark it **available at buildtime** too (Railpack needs a bootable app during `vite build`, for the Wayfinder route-generation step) |
| `APP_URL` | your public URL |
| `APP_TIMEZONE` | `Europe/Madrid` (the scheduler's time-window sync jobs depend on this) |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | from your MySQL resource |
| `SESSION_DRIVER` | `database` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` (no queued jobs exist today, so a worker would just be an idle process) |
| `LOG_CHANNEL` | `stderr` (so Coolify's log viewer / `docker logs` captures it) |
| `INERTIA_SSR_ENABLED` | `false` — see [SSR](#ssr) |
| `LA_LIGA_LOGIN_EMAIL` / `LA_LIGA_LOGIN_PASSWORD` | your real La Liga Fantasy account credentials |
| the other `LA_LIGA_*` vars | copy as-is from `.env.example`, not secrets |
| `RAILPACK_BUILD_APT_PACKAGES` | `bison re2c libcurl4-openssl-dev libonig-dev libicu-dev libxml2-dev libzip-dev libreadline-dev libpq-dev libgd-dev` — **required on an ARM64 Coolify host** (see below), harmless if unused on x86_64 |

Unlike Nixpacks, there's no `NIXPACKS_PHP_ROOT_DIR` / `NIXPACKS_PHP_FALLBACK_PATH`
/ `IS_LARAVEL` to set — Railpack's PHP provider detects the `artisan` file
and points FrankenPHP at `public/` with the right fallback on its own.

**ARM64 hosts:** mise (Railpack's toolchain installer) has no precompiled
PHP 8.5.9 binary for `linux-arm64` yet, so on an ARM64 Coolify host it
compiles PHP from source, which needs `bison` (and likely `re2c`) present
in the build container — neither is in Railpack's builder image by
default. Confirmed on a real deploy attempt: the build failed with
`configure: error: bison 3.0.0 or newer is required to generate PHP
parsers` after `checking for bison... no`. Setting
`RAILPACK_BUILD_APT_PACKAGES=bison re2c` installs them for the build step
only (not the final runtime image). Node needed no such workaround — a
precompiled `node-v26.8.2-linux-arm64` binary exists, no compile step.

## 4. Migrations & caching

**No Pre-deployment Command needed.** FrankenPHP's own startup script (baked
into the image by Railpack) already runs `php artisan migrate --force`
followed by `storage:link`, `optimize:clear`, `optimize` every time the
container boots, before it starts accepting traffic — this replaces the
Nixpacks setup's manual Coolify **Pre-deployment Command** field entirely.
If a migration fails, the container never becomes healthy and Coolify's
health check keeps routing to the previous one, which is the same safety
property the old manual pre-deploy command gave.

If you ever need to skip the automatic migration for a one-off deploy, set
`RAILPACK_SKIP_MIGRATIONS=true` for that deploy.

## 5. Persistent storage

Player photos are synced at runtime (`season:sync-player-photos`, daily)
into `storage/app/public/images/player/`, symlinked to `public/storage` —
the `storage:link` call above runs on every boot. That directory is **not
part of the build**, so without a volume every deploy wipes it and photos
just re-download on the next daily sync (harmless, but avoidable, and means
broken images for anyone browsing right after a deploy until that sync
runs).

Add a persistent volume on the app resource:

- Mount path: `/app/storage/app/public`

Team crests, by contrast, are committed straight into `public/images/teams/`
and ship with every build — no volume needed for those.

## 6. The scheduler

The container only runs the FrankenPHP server — nothing inside it fires the
`season:sync-*` commands on its own. Add a Coolify **Scheduled Task** on
this app resource instead (Settings → Scheduled Tasks): it works by
`docker exec`-ing into the running container, which is exactly what a cron
line would do anyway.

- Command: `php artisan schedule:run`
- Frequency: `* * * * *` (every minute)

This one line is enough even for the app's every-10-seconds market sync:
Laravel's scheduler detects any sub-minute frequency and internally loops
for the rest of that minute to fire it on time, rather than needing a
literal 10-second cron line (which cron can't express anyway).

## SSR

Inertia SSR is disabled here (`INERTIA_SSR_ENABLED=false`) on purpose: it
exists to help SEO and first-paint on public, high-traffic sites, and buys
you neither here — this is a private tool for a small friend league, not
indexed anywhere. Running it would mean keeping a second, always-on Node
process alive in the container for no real benefit, exactly the extra
moving part this deployment is trying to avoid. Flip it back on later if
that ever changes — the SSR bundle build step and `bootstrap/ssr/` output
still work locally (`npm run build:ssr`), this only turns off *running* it
in production.

## Known rough edges

A real Coolify deploy on 2026-09-10 confirmed the ARM64 `bison`/`re2c`
compile issue above (now fixed via `RAILPACK_BUILD_APT_PACKAGES`) — that
build got as far as compiling PHP extensions before failing, so the
Railpack detection, image pulls, and apt/mise setup all work as expected.
The rest of the pipeline (composer install, npm build, the FrankenPHP
start-container script, migrations-on-boot) is still unverified end-to-end.
Keep watching build logs on the next deploy attempt for:

- `railpack.json`'s `steps.install` appends `composer install --no-dev
  --no-interaction --optimize-autoloader` *after* Railpack's own default
  install step (via the `"..."` entry), rather than replacing it, since
  it's undocumented whether a full replacement would also drop the Node
  `npm install` Railpack normally runs alongside the PHP install. This
  means composer install effectively runs twice (once with dev deps, once
  without) — wasteful but should net out to the same `--no-dev` vendor
  tree. Confirm `vendor/` in the running container doesn't contain
  `phpunit`, `pest`, etc.
- The automatic `php artisan migrate --force` on every boot (see
  [Migrations & caching](#migrations--caching)) is a behavior change from
  the old Nixpacks setup, where migrations only ran through Coolify's
  gated Pre-deployment Command. Watch the first deploy's logs to confirm
  it runs and succeeds before traffic is expected to flow.
