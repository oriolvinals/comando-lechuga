# Deploying to Coolify

Coolify builds this from the `Dockerfile` at the repo root, with
`docker/Caddyfile` and `docker/entrypoint.sh` alongside it. This replaced
Railpack (see [Why not Railpack](#why-not-railpack) below) — the Dockerfile
is a two-stage build (`build`, then a slim runtime stage) based on
`dunglas/frankenphp` (a single Caddy + PHP binary), so there's still no
nginx + php-fpm + supervisor stack to hand-roll.

`docker/entrypoint.sh` runs on every container start: `php artisan migrate
--force`, then `storage:link`, `optimize:clear`, `optimize`, before handing
off to `frankenphp run`. See [Migrations & caching](#migrations--caching)
below for what that means for how deploys are gated.

## 1. App resource settings

- **Build Pack**: Dockerfile
- **Port**: whatever Coolify injects via `$PORT` — `docker/Caddyfile` listens
  on `:{$PORT}` — also set as the container's exposed port
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
| `APP_KEY` | generate once with `php artisan key:generate --show`, paste the result — **never regenerate on redeploy**, it invalidates every session/cookie. Mark it **available at buildtime** too — the `Dockerfile`'s `build` stage declares `ARG APP_KEY` because `vite build` needs a bootable app for the Wayfinder route-generation step, and Coolify passes buildtime-flagged variables through as `--build-arg` |
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

No `RAILPACK_*` variables, `NIXPACKS_PHP_ROOT_DIR` / `NIXPACKS_PHP_FALLBACK_PATH`
/ `IS_LARAVEL` to set — remove any of those left over from a previous setup.
If you need to skip the migration step on the container's first boot for a
one-off deploy, set `SKIP_MIGRATIONS=true` for that deploy (read by
`docker/entrypoint.sh`, not a build-pack feature).

**ARM64 hosts just work here** — `dunglas/frankenphp` ships precompiled
multi-arch images (amd64/arm64), so there's no PHP-from-source compile step
regardless of host architecture. That's the whole reason this replaced
Railpack; see [Why not Railpack](#why-not-railpack).

## 4. Migrations & caching

**No Pre-deployment Command needed.** `docker/entrypoint.sh` already runs
`php artisan migrate --force` followed by `storage:link`, `optimize:clear`,
`optimize` every time the container boots, before it starts accepting
traffic — there's no Coolify **Pre-deployment Command** field to fill in.
If a migration fails, the container never becomes healthy and Coolify's
health check keeps routing to the previous one.

If you ever need to skip the automatic migration for a one-off deploy, set
`SKIP_MIGRATIONS=true` for that deploy.

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

## Why not Railpack

This app was briefly deployed via Railpack (Railway's build system, which
Coolify supports as an alternative build pack) instead of a hand-written
Dockerfile. It was dropped because Railpack's PHP provider installs PHP
through `mise`, which has no precompiled PHP binary for `linux-arm64` — on
an ARM64 Coolify host it compiled PHP from source on every build with a cold
cache, taking ~20 minutes and occasionally failing outright (a known
mise/asdf-php gap, not specific to this app — see
[jdx/mise#4720](https://github.com/jdx/mise/discussions/4720)). Three real
deploy attempts each got one step further through that compile — missing
`bison`/`re2c`, then `libcurl` dev headers, then `gdlib` — before the
underlying slowness/fragility made the whole approach not worth it.
`dunglas/frankenphp`'s own images ship precompiled multi-arch (amd64/arm64)
PHP builds, which is the whole point of switching to a Dockerfile: no
from-source PHP compile on any host architecture.

If Railpack is ever reconsidered, the `RAILPACK_BUILD_APT_PACKAGES`
workaround (installing `bison re2c libcurl4-openssl-dev libonig-dev
libicu-dev libxml2-dev libzip-dev libreadline-dev libpq-dev libgd-dev
libjpeg-dev libpng-dev` for the build step) is what got furthest before this
was abandoned in favor of the Dockerfile.

## Zombie processes and the healthcheck

FrankenPHP runs as PID 1 in the container and does not reap orphaned zombie
processes — e.g. subprocesses left behind by Laravel's scheduler (the
every-10-seconds market sync, or the Coolify Scheduled Task's per-minute
`docker exec ... php artisan schedule:run`, see below). Over several hours
these accumulate (confirmed via `ps` on a real deploy: hundreds of `[php]
<defunct>` / `[sh] <defunct>` entries parented to PID 1) until Docker can no
longer exec **any** new process into the container — including the
`curl -f http://localhost:2019/metrics` healthcheck Coolify injects
automatically, which then fails with `OCI runtime exec failed: ... procReady
not received`. Docker marks the container unhealthy, Traefik stops routing
to it, and the public site returns "no available server" until the next
redeploy resets the process table. The `Dockerfile` installs `tini` and uses
it as the real PID 1 (`ENTRYPOINT ["/usr/bin/tini", "--",
"/usr/local/bin/entrypoint.sh"]`) specifically to reap these.

## Known rough edges

**Not build-tested end-to-end.** The Dockerfile/Caddyfile/entrypoint were
written and reviewed but never run through a real `docker build` (no local
Docker available in the environment they were written in) or a real Coolify
deploy. Watch the first real deploy's logs closely for:

- The two-stage image build succeeding at all — base image pulls,
  `install-php-extensions`, copying the Node binaries from the `node`
  image into the `dunglas/frankenphp` stage, `composer install`, `npm ci`
  and `npm run build` (including the Wayfinder route-generation step,
  which needs `APP_KEY` set as a build arg — see the env var table above).
- `docker/entrypoint.sh` actually running on container start: `php artisan
  migrate --force`, `storage:link`, `optimize:clear`, `optimize`, then
  `frankenphp run`. Confirm migrations apply and the container becomes
  healthy before traffic is expected to flow.
- `docker/Caddyfile` listening on Coolify's injected `$PORT` and serving
  `/up` successfully.
