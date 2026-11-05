# Deployment

ediarum.webdav runs as containers, as part of the ediarum.platform stack. One image
([`Dockerfile`](../Dockerfile)) serves every process; [`compose.yaml`](../compose.yaml) wires
them up:

| Service | Command | What |
|---|---|---|
| `webdav` | default (php-fpm + nginx) | HTTP on port 8080, docroot `public/` |
| `webdav-migrate` | `php artisan migrate --force && php artisan db:seed --force` | runs once per `up`; the others wait until it exited 0 |
| `webdav-queue` (×2) | `php artisan queue:work --queue=default,git-remote,ediarum-services,exist-db` | Git remote pushes, eXist-db and ediarum services syncs |
| `webdav-scheduler` | `php artisan schedule:work` | `app:increase-lock-timeout` every minute (keeps WebDAV locks from expiring), `queue:prune-failed --hours=168` daily |
| `webdav-db` | `mariadb:11` | named volume `webdav-db` |

There is no nginx container, no published port and no Reverb/WebSocket container
(`BROADCAST_DRIVER=log`). The platform's reverse proxy is the only public entry point and
reaches the app at `http://webdav:8080`. The queue workers get `stop_grace_period: 1h`, so a
running push or sync can finish on `docker compose down` / redeploy.

## Configuration

All configuration comes from `${VAR}` interpolation in `compose.yaml`; the containers have no
`.env` file. [`.env.example`](../.env.example) lists every variable. Required:
`APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `WEBDAV_REPOSITORIES_DIR`; Compose refuses to
start without them.

Fixed by `compose.yaml` and not configurable: `APP_ENV=production`, `APP_DEBUG=false`,
MariaDB at `webdav-db:3306`, `QUEUE_CONNECTION`/`CACHE_DRIVER`/`SESSION_DRIVER=database`
(several containers, no shared local files), `LOG_CHANNEL=stderr` (read with
`docker compose logs`), `SESSION_COOKIE=ediarum_session` without a cookie domain (platform ADR
0004), `TRUSTED_PROXIES=*` (client IP and scheme from the proxy's `X-Forwarded-*`; the login
rate limit is per IP).

The app reads some variables with `env()` outside `config/`, so the image never runs
`config:cache`.

### Repositories

`WEBDAV_REPOSITORIES_DIR` is a host directory, mounted at `/data/repositories` into `webdav`,
`webdav-queue` and `webdav-scheduler` (the jobs commit and push from there). It holds one git
repository per project slug. The containers run as `www-data` (uid 33), which must be able to
write it. The image trusts every directory for git (`safe.directory '*'`), since the owner
differs.

### First admin

`webdav-migrate` runs `db:seed` after the migrations. The seeder creates the admin from
`INITIAL_USER_NAME` / `INITIAL_USER_EMAIL` / `INITIAL_USER_PASSWORD` only while the `users`
table is empty, so it is safe on every start. Change the password after the first login.

## Behind ediarum.platform

The platform pulls this repo into its `compose.yaml`, pinned to a release tag:

```yaml
include:
  - path: https://github.com/ediarum/ediarum.WEBDAV.git#<tag>:compose.yaml
    env_file: ./env/webdav.env
```

`env/webdav.env` holds the variables from `.env.example`. `compose.override.yaml` from this
repo is not loaded through `include`.

ediarum.webdav has no UI pages of its own. Its admin frontend runs inside the portal, and webdav
is the platform's auth provider: the portal's login and profile pages, and the backends of the
other apps, use its session (see the platform's `docs/auth-contract.md` and `src/contract.ts`).
Laravel serves, at the root of the container:

| Path | What |
|---|---|
| `/api/auth/…` | auth provider: `csrf-cookie`, `login`, `logout`, `me`, `password`, `verify`, `projects` |
| `/api/webdav/…` | JSON API of the admin frontend (admins only) |
| `/apps/webdav/…` | the built admin frontend (`embed.js` + `assets/`), static files |
| `/connection/{slug}/…` | the WebDAV endpoint for editors |

### Platform reverse proxy

```nginx
location /api/auth/          { proxy_pass http://webdav:8080; }
location /api/webdav/        { proxy_pass http://webdav:8080; proxy_buffering off; }
location /apps/webdav/       { proxy_pass http://webdav:8080; }
location /webdav/connection/ { proxy_pass http://webdav:8080/connection/; proxy_request_buffering off; }
```

plus `Host`, `X-Forwarded-For` and `X-Forwarded-Proto` headers. Only the WebDAV endpoint has a
public prefix: editors connect to `https://ediarum.example.org/webdav/connection/<slug>/`. The
proxy strips `/webdav`, and `APP_SUBPATH=/webdav` puts it back into the URLs sabre/dav returns
(`<d:href>`, lock roots) and expects (`Destination` on MOVE/COPY).

`proxy_buffering off` lets the progress streams (initialize project, eXist-db sync, retries)
through; the responses also send `X-Accel-Buffering: no`, which the in-image nginx honours as
well. Other app backends call `verify` over the internal network:
`AUTH_VERIFY_URL=http://webdav:8080/api/auth/verify`.

### Brute-force protection

- Platform login (`POST /api/auth/login`): 5 failed attempts per email and IP, then a lockout of
  60 seconds (`LoginRequest`).
- WebDAV endpoint (HTTP Basic auth): 10 failed logins per username and IP, or 30 per IP across all
  usernames, within 10 minutes; then 429 with `Retry-After` until the window ends
  (`ThrottleFailedBasicAuth`). Only 401s to requests that sent credentials count, so Oxygen's
  challenge request and working clients are never slowed down.

Both limits use the client IP, so `TRUSTED_PROXIES` must be right (see Configuration). The
counters live in the cache (`CACHE_DRIVER=database`), shared by all containers. A rate limit on
the reverse proxy for `/api/auth/login` adds a second layer.

### In-image nginx

The image is `serversideup/php` (fpm-nginx); [`docker/nginx/webdav.conf`](../docker/nginx/webdav.conf)
adds:

- `/connection/` goes straight to `index.php`, so the base image's rules (deny dotfiles and
  `*.bak`/`*.ini`/`*~`, serve `*.css`/`*.js` as static files) don't apply to editors' files.
- `/api/webdav/` gets a 1 h FastCGI read timeout for the progress streams.
- `embed.js` keeps its name across builds and is sent with `Cache-Control: no-cache`; the hashed
  files in `assets/` are `immutable`.

Uploads are limited to 100 MB (`NGINX_CLIENT_MAX_BODY_SIZE`, `PHP_POST_MAX_SIZE`,
`PHP_UPLOAD_MAX_FILE_SIZE` in the base image).

### Register the app in the portal

Add this entry to the portal's `apps.json`:

```json
{ "id": "webdav", "label": "WebDAV", "entry": "/apps/webdav/embed.js", "apiBase": "/api/webdav", "roles": ["admin"] }
```

Only users with the `admin` role (`is_admin`) see the app. Regular users only use the portal's
login and profile, and WebDAV itself.

## Standalone

In this repo, with a filled-in `.env` (from `.env.example`):

```bash
docker compose up -d --build
docker compose ps                               # webdav-migrate exited 0; the rest running/healthy
curl -i localhost:8080/api/auth/csrf-cookie     # 204 + XSRF-TOKEN cookie
docker compose logs -f webdav-scheduler         # increase-lock-timeout every minute
```

`compose.override.yaml` publishes port 8080. Over plain HTTP set `SESSION_SECURE_COOKIE=false`,
or the browser drops the session cookie.

Useful commands:

```bash
docker compose exec webdav php artisan tinker
docker compose exec webdav php artisan queue:restart    # after changing job code without a redeploy
docker compose exec webdav php artisan queue:prune-failed --hours=168
```

## Releases

The platform pins a tag of this repo in its `include`. Tag a release (`vX.Y.Z`) on `main`
and push it; the platform builds the image from the remote, so the commits must be pushed too.

## Git remote

If a project has a Git remote URL, username and personal access token (PAT), every new commit
is pushed to the Git remote automatically (on the `git-remote` queue). Any Git host that accepts
HTTPS pushes with a username and token works, e.g. GitLab or GitHub.

## Without Docker

Possible, but no longer the supported setup. You need PHP 8.3 with `ds`, `xsl`, `pdo_mysql`,
`dom` and `zip`, git, MariaDB/MySQL, and node 20 for `npm run build`. Then provide by hand what
compose does: nginx serving `public/` with the rules from `docker/nginx/webdav.conf`, two queue
workers (`queue:work --queue=default,git-remote,ediarum-services,exist-db`, e.g. under supervisor
with `stopwaitsecs=3600`) and a cron entry `* * * * * php artisan schedule:run`.

The `X-WebDAV-Proxy-Root` header (middleware `webdav.proxy.*`) is a leftover from an older
proxy setup and is being phased out; the platform does not use it.

## Usage

* For usage see [USAGE.md](USAGE.md).
