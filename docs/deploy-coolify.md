# Deploying to Coolify

No Docker involved — Coolify builds this with **Nixpacks** directly from
`nixpacks.toml` at the repo root. Nixpacks auto-detects the PHP/Node
toolchain (from `composer.json` / `package.json`); the toml file only adds
to that default plan (the `"..."` entries keep everything Nixpacks already
decided and layer the extra setup on top).

The built image runs exactly two processes under supervisor (auto-restart
on crash): **nginx** and **php-fpm**. Nothing else — no queue worker
(nothing in this app dispatches a queued job) and no SSR node process. See
[SSR](#ssr) below for why.

## 1. App resource settings

- **Build Pack**: Nixpacks
- **Port**: `80` (also set as the container's exposed port)
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
| `APP_KEY` | generate once with `php artisan key:generate --show`, paste the result — **never regenerate on redeploy**, it invalidates every session/cookie. Mark it **available at buildtime** too (Nixpacks needs a bootable app during `vite build`, for the Wayfinder route-generation step) |
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
| `NIXPACKS_PHP_ROOT_DIR` | `/app/public` — the nginx template in `nixpacks.toml` falls back to `/app` without this, which would serve `.env` and the whole app tree as static files |
| `NIXPACKS_PHP_FALLBACK_PATH` | `/index.php` |
| `IS_LARAVEL` | `true` |

## 4. Pre-deployment command

Set this in the app's **General → Pre-deployment Command** field. Coolify
runs it inside the freshly-built container and only routes traffic to it
if the command exits `0` — if a migration fails, the previous container
just keeps serving:

```
php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 5. Persistent storage

Player photos are synced at runtime (`season:sync-player-photos`, daily)
into `storage/app/public/images/player/`, symlinked to `public/storage` —
`nixpacks.toml`'s `start.sh` runs `storage:link` on every boot. That
directory is **not part of the build**, so without a volume every deploy
wipes it and photos just re-download on the next daily sync (harmless, but
avoidable, and means broken images for anyone browsing right after a
deploy until that sync runs).

Add a persistent volume on the app resource:

- Mount path: `/app/storage/app/public`

Team crests, by contrast, are committed straight into `public/images/teams/`
and ship with every build — no volume needed for those.

## 6. The scheduler

The container only runs nginx + php-fpm — nothing inside it fires the
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

## Not build-tested

Nixpacks/nix isn't available in the environment this file was written in,
so this hasn't gone through an actual `nixpacks build` / Coolify deploy —
it's assembled from Coolify's own documented Laravel/Nixpacks pattern and
working examples, not verified end-to-end. Test the first deploy for real
before relying on it, and check the build logs closely — the two most
likely failure points are the `nginx.template.conf` template syntax
(Nixpacks' own `$if(...)`/`${VAR}` engine) and the supervisor process
definitions in `nixpacks.toml`'s `[staticAssets]`.

One real issue already hit and fixed: Nixpacks' *default* install step runs
`composer install` without `--no-dev`, which pulls in `phpunit/phpunit`.
That version's `Runner/Version.php` uses PHP 8.4's "call a method directly
on `new`" syntax (`new VersionId(...)->asString()`), which some PHP
versions can't even parse — `nunomaduro/collision` (a real, non-dev
dependency) touches that class at autoload time regardless, so the build
crashed on `composer install`'s `post-autoload-dump` step. Fixed by
overriding `[phases.install]` in `nixpacks.toml` to pass `--no-dev` (skips
installing that tooling at all — it's never needed to build or run the
app) and by requiring `"php": "^8.5"` in `composer.json` so the app's own
build/runtime PHP is new enough regardless.
