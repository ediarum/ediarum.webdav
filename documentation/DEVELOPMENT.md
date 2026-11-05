# Development

## Local Development with Docker

Runs the production setup (see [DEPLOYMENT.md](DEPLOYMENT.md)): web server, two queue workers,
scheduler and MariaDB.

1. `cp .env.example .env` and fill in at least `APP_KEY` (`php artisan key:generate --show`, or
   any `base64:` + 32 random bytes), `DB_PASSWORD`, `DB_ROOT_PASSWORD` and
   `WEBDAV_REPOSITORIES_DIR` (writable by uid 33). Set `SESSION_SECURE_COOKIE=false` and
   `APP_URL=http://localhost:8080` for plain HTTP, and `APP_SUBPATH=` (empty), since no proxy
   strips `/webdav` here (see [Installation](#installation)).
2. `docker compose up -d --build`. `compose.override.yaml` publishes the app on
   <http://localhost:8080>; the first admin comes from `INITIAL_USER_*`.
3. Run the portal against it: `EDIARUM_PROXY_TARGET=http://localhost:8080 npm run dev:proxy` in
   ediarum.platform (see [Accessing the Application](#accessing-the-application)).

The image contains the code, so rebuild (`docker compose up -d --build`) after changes.
`docker compose logs -f` shows the logs of all services.

## Local Development without Docker

### Requirements

* **PHP 8.3+ (for current version info check composer.json)**
  * Check php version with `php -v`.
* **A running MySQL database connection.** If you having problems to install mysql you can use a docker container. For a local installation see the following:
  1. Check MySQL Version with `mysql --version`.
  2. Check if MySQL is running:
   - **Linux/macOS**: `sudo systemctl status mysql`. If not running: `sudo systemctl start mysql`.
   - **Windows**: Run `sc query MySQL84` (replace `MySQL84` with your service name). To start: `net start MySQL84`.
  3. Check if you can connect to MySQL: `mysql -u root -p` with user `root`. For [creating users](https://dev.mysql.com/doc/refman/8.4/en/create-user.html) and [setting password](https://dev.mysql.com/doc/refman/8.4/en/set-password.html) please read the [MySQL Reference Manual](https://dev.mysql.com/doc/refman/8.4/en/)
* **Node.js Version 20**
  * Check node version with `node --version`.

If you do not meet the requirements, please follow the installation instructions at https://laravel.com/docs/12.x/installation and https://dev.mysql.com/downloads/installer/.

### Installation

1. Copy the `.env` file:
   - **Linux/macOS**: `cp .env.example .env`
   - **Windows (PowerShell)**: `Copy-Item .env.example .env`
   - **Windows (CMD)**: `copy .env.example .env`
2. Set up the database connection by modifying `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in the `.env` file. See [Environment Variables](#environment-variables) below for details.
   Also change these three values, which `.env.example` sets for production behind the platform:
   - `APP_URL=http://localhost:8000`
   - `APP_SUBPATH=` (empty): in production the platform proxy serves webdav under `/webdav` and
     strips that prefix, and webdav adds it back to every URL it generates. Locally nothing is in
     front of `php artisan serve`, so with `/webdav` the WebDAV listings would link to
     `http://localhost:8000/webdav/connection/…`, which doesn't exist.
   - `SESSION_SECURE_COOKIE=false`: the browser only keeps a secure cookie over HTTPS. Otherwise
     the login seems to work, but the next request has no session and returns 401.
3. Run `composer install` to install php dependencies.
4. Run `npm install` to install frontend dependencies. On Windows, run this command as Administrator (right-click Command Prompt/PowerShell → "Run as administrator").

#### Quick Setup (Automated)

Run this command to set up the entire database automatically (migrations, seeds, key generation):

```bash
php artisan setup:fresh
```

Then proceed to step 5 below.

#### Manual Setup (Step-by-Step)

If you prefer to run setup steps individually:

```bash
php artisan migrate:install
php artisan migrate
php artisan db:seed
php artisan key:generate
```

#### Starting the Application

```bash
composer dev
```

This starts everything in one terminal (Ctrl+C stops all of it):

| Name | Process |
|---|---|
| `server` | `php artisan serve` on http://localhost:8000 |
| `queue` | queue worker for all queues |
| `logs` | `php artisan pail`, the Laravel log |
| `vite` | `npm run dev`, rebuilds the admin frontend |

The login and the admin frontend need the ediarum.platform portal, which you start yourself from
its own checkout; see [Accessing the Application](#accessing-the-application).

To run the parts separately instead, you'll need these terminals:

**Terminal 1 - Laravel Server:**
```bash
php artisan serve
```
This starts the web server on http://localhost:8000

**Terminal 2 - Admin Frontend Build:**
```bash
npm run dev
```
Rebuilds the admin frontend (`resources/spa/`) into `public/apps/webdav/` on every change
(`vite build --watch`; there is no dev server, because the portal loads the built `embed.js`).
Run `npm run type-check` to type-check it.

**Terminal 3 - Queue Worker (if using integrations):**
```bash
php artisan queue:listen --queue=default,git-remote,ediarum-services,exist-db
```
Only needed if you're using a Git remote, eXist-db or ediarum services synchronization.

#### Accessing the Application

ediarum.webdav has no UI pages of its own: the admin frontend runs inside the ediarum.platform
portal, which also provides the login and profile pages (and uses webdav as its auth provider).
Locally there are two ways to open it.

**Standalone, without the portal** (`APP_ENV=local` only): open <http://localhost:8000/webdav/>
(<http://localhost:8000/> redirects there). A dev-only page mounts the admin frontend the way the
portal does and logs you in as the first admin (the one `db:seed` creates from `INITIAL_USER_*`),
so there is no login page, top bar, profile or logout. If you already have a session (for example
from the portal), it is kept. Outside `APP_ENV=local` these URLs are a 404. Use this for work on
the admin frontend and its API; to test the login itself, use the portal.

**With the portal**: start it yourself from a checkout of ediarum.platform, against this server:

```bash
# in ediarum.platform (once: npm ci)
EDIARUM_PROXY_TARGET=http://localhost:8000 npm run dev:proxy
```

The portal's default `public/config/apps.json` already lists the webdav app, so no
`EDIARUM_APPS_JSON` is needed. The portal forwards `/api/…` and `/apps/…` to Laravel, so the browser only talks to
`http://localhost:5173`. Open it and log in with credentials from your `.env` file:
- **Email**: `INITIAL_USER_EMAIL`
- **Password**: `INITIAL_USER_PASSWORD`

The admin frontend is then at `http://localhost:5173/webdav/`.


### Environment Variables

The `.env` file contains configuration for your local development environment. Key variables:

#### **Required (Database & Application)**
- `DB_HOST` – MySQL server hostname (default: `127.0.0.1`)
- `DB_PORT` – MySQL port (default: `3306`)
- `DB_DATABASE` – MySQL database name (default: `ediarum_webdav`)
- `DB_USERNAME` – MySQL user (default: `root`)
- `DB_PASSWORD` – MySQL user password
- `APP_KEY` – Encryption key (auto-generated via `php artisan key:generate`)
- `INITIAL_USER_NAME` – Name of first admin user
- `INITIAL_USER_EMAIL` – Email of first admin user
- `INITIAL_USER_PASSWORD` – Password for seeded admin user
- `GIT_PATH` - Path to git.exe on your machine (e.g. `C:\Program Files\Git\bin\git.exe`)
- `APP_GIT_USER_NAME`, `APP_GIT_USER_EMAIL` – Author of the commits webdav makes for WebDAV changes

#### **Optional (Project Integrations)**

These are configured per-project in the web UI, not in `.env`:
- **Git remote** (e.g. GitLab, GitHub): URL, username, personal access token (configured in Project settings)
- **eXist-db**: Base URL, data path, username, password (configured in Project settings)
- **ediarum services**: on/off per project (Project settings); URL and token are global, in `.env` (`EDIARUM_SERVICES_URL`, `EDIARUM_SERVICES_API_KEY`)

#### **Development Settings**
- `APP_DEBUG` – Set to `true` for development (shows detailed errors)
- `LOG_LEVEL` – Log verbosity (`debug`, `info`, `warning`, `error`)
- `QUEUE_CONNECTION` – Set to `database` for local development (jobs stored in DB, processed by queue worker)

For all available variables, see `.env.example` in the project root.

---

### Setting up the Database

If starting from an empty database:

1. **Create the MySQL database**:
   ```bash
   mysql -u root -p
   CREATE DATABASE ediarum_webdav;
   EXIT;
   ```
   (Replace `ediarum_webdav` with your `DB_DATABASE` value from `.env`)

2. **Run automated setup** (recommended):
   ```bash
   php artisan setup:fresh
   ```
   This command handles all remaining steps automatically.

   **OR** run steps manually:
   ```bash
   php artisan migrate:install     # Create migrations table
   php artisan migrate             # Run all migrations for setting up the database
   php artisan db:seed            # Add initial data to the database
   php artisan key:generate       # Generate APP_KEY
   ```

#### Inspect the database

With `mysql -u root -p` you can login the database via command tool:

* `SHOW DATABASES;` Shows the current databases.
* To check or edit the database run `USE ediarum_webdav`. Then run `SHOW TABLES;` to list the tables:

```txt
+--------------------------+
| Tables_in_ediarum_webdav |
+--------------------------+
| failed_jobs              |
| jobs                     |
| migrations               |
| password_reset_tokens    |
| personal_access_tokens   |
| project_user             |
| projects                 |
| users                    |
+--------------------------+
```

After adding a project with `ID` there should be a new table named `locks_ID` for tracking the webdav locks for this project, e.g.:

```txt
+--------------------------+
| Tables_in_ediarum_webdav |
+--------------------------+
| failed_jobs              |
| jobs                     |
| locks_1                  |
| migrations               |
| password_reset_tokens    |
| personal_access_tokens   |
| project_user             |
| projects                 |
| users                    |
+--------------------------+
```

## Troubleshooting

Common issues and solutions:

### MySQL Connection Issues

**Problem**: `SQLSTATE[HY000]: General error: 1030 Got error ...`

**Solution**: 
- Verify MySQL is running: `mysql -u root -p -e "SELECT 1;"`
- Check `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` in `.env`
- Ensure database exists: `CREATE DATABASE ediarum_webdav;`

### Key Already Exists

**Problem**: `Key already exists in config cache`

**Solution**: Clear caches and regenerate key
```bash
php artisan config:clear
php artisan key:generate
```

### Laravel Server Won't Start

**Problem**: `Address already in use` or port 8000 is occupied

**Solution**: Use a different port
```bash
php artisan serve --port=8001
```

### Queue Jobs Not Processing

**Problem**: Jobs are queued but don't execute

**Solution**:
- Ensure queue worker is running: `php artisan queue:listen --queue=default,git-remote,ediarum-services,exist-db`
- Check database connection (jobs table must be accessible)
- View failed jobs: See ProjectController dashboard

### More Issues?

If you encounter an issue not listed here, please:
1. Check Laravel logs in `storage/logs/`
2. Enable debug mode: Set `APP_DEBUG=true` in `.env`
3. Run `php artisan tinker` to test database connection
4. Check [Laravel Troubleshooting Docs](https://laravel.com/docs/12.x/deployment#configuration)

### UI Not Loading / Assets Missing

**Problem**: Page loads but looks broken (no styling, broken layout)

**Solution**:
- Ensure `npm run dev` is running in a separate terminal (required for Vite asset bundling)
- Check browser console (F12) for 404 errors on CSS/JS files
- Stop `npm run dev` and restart it: `npm run dev`
- Clear browser cache: Ctrl+Shift+Delete (or Cmd+Shift+Delete on Mac)

### Frontend Changes Not Showing

**Problem**: You made edits to Vue files or CSS but changes don't appear

**Solution**:
- Ensure `npm run dev` is running (enables hot reload)
- Check terminal output of `npm run dev` for build errors
- If errors exist, fix them and save the file again to trigger rebuild
- Hard refresh browser: Ctrl+F5 (or Cmd+Shift+R on Mac)

### Git remote synchronization

**Problem**: Commits don't appear on the Git remote

**Solution**:

1. Ensure queue worker is running: `php artisan queue:listen --queue=default,git-remote,ediarum-services,exist-db`
2. Ensure the branch is not protected against pushes on the Git host. GitLab: `Project → Settings → Repository → Protected branches → Unprotect`; GitHub: `Repository → Settings → Branches` (branch protection rules).

---

## Usage

* For usage see [USAGE.md](USAGE.md).
