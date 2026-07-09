# Templates.uz Provisioner

A deliberately tiny, standalone PHP program (no framework, no Composer
dependencies). It is the **only** thing on the server allowed to touch
Docker or create MySQL databases/users. Templates.uz (the Laravel app) never
holds either privilege — it only sends authenticated HTTP requests here.

Read the whole file before deploying — this touches root-equivalent access
(`docker` group membership) on a live server.

## What it does

- `GET  /health`    — no auth. Liveness + Docker availability check.
- `POST /provision` — creates `/var/www/customers/{subdomain}/` and a
  resource-limited Docker container (`--cpus`, `--memory`, `--pids-limit`)
  bind-mounted to it, read-write. Does **not** deploy any code.
- `POST /database`  — creates an isolated MySQL database + user + random password.
- `POST /destroy`   — removes the container (and the subdomain's database, if
  `drop_database: true` is given — see "Database records" below). **Never**
  deletes `/var/www/customers/{subdomain}/` — that's code you deployed by
  hand, so a project deletion in Templates.uz can't destroy it out from under
  you.
- `GET  /stats?subdomain=...` — CPU/RAM usage for a running container.

`/provision` and `/destroy` also wire up (and tear down) the subdomain's
Nginx routing automatically — see "Nginx automation setup" below for the
one-time setup this requires. If that setup hasn't been done yet, the
container is still created successfully and `/provision`'s response reports
`"nginx_configured": false` with an `"nginx_error"` explaining why, so a
missing Nginx setup never blocks getting a working container.

## Server setup

### 1. Install Docker

```bash
curl -fsSL https://get.docker.com | sh
```

### 2. Create a dedicated OS user for this program

```bash
sudo useradd -r -s /usr/sbin/nologin templates-uz-provisioner
sudo usermod -aG docker templates-uz-provisioner
sudo mkdir -p /var/www/customers
sudo chown templates-uz-provisioner:templates-uz-provisioner /var/www/customers
```

Being in the `docker` group is root-equivalent — this is exactly why this
program is small, separate, and runs as its own user instead of living
inside the main Templates.uz app.

### 3. Create a dedicated MySQL user (not root)

```sql
CREATE USER 'provisioner'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';
GRANT CREATE, CREATE USER, GRANT OPTION ON *.* TO 'provisioner'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### 4. Deploy this code

```bash
sudo mkdir -p /opt/templates-uz-provisioner
sudo cp -r . /opt/templates-uz-provisioner
cd /opt/templates-uz-provisioner
cp .env.example .env
# edit .env: PROVISIONER_TOKEN (must match Templates.uz's PROVISIONER_TOKEN),
# PROVISIONER_DB_USERNAME/PASSWORD from step 3.
sudo chown -R templates-uz-provisioner:templates-uz-provisioner /opt/templates-uz-provisioner
```

### 5. Its own PHP-FPM pool

`/etc/php/8.3/fpm/pool.d/templates-uz-provisioner.conf`:

```ini
[templates-uz-provisioner]
user = templates-uz-provisioner
group = templates-uz-provisioner
listen = /run/php/templates-uz-provisioner.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 5
env[PROVISIONER_TOKEN] = $PROVISIONER_TOKEN
env[PROVISIONER_CUSTOMERS_ROOT] = /var/www/customers
env[PROVISIONER_DB_HOST] = 127.0.0.1
env[PROVISIONER_DB_PORT] = 3306
env[PROVISIONER_DB_USERNAME] = provisioner
env[PROVISIONER_DB_PASSWORD] = CHANGE_ME
```

PHP-FPM's `env[]` directives don't read `.env` files automatically — either
paste the real values here directly (this file should be `chmod 600`, owned
by root), or use `EnvironmentFile=` in the systemd unit below instead and
switch this pool config to inherit the process environment
(`clear_env = no`).

Reload: `sudo systemctl restart php8.3-fpm`

### 6. Nginx — localhost only, never public

`/etc/nginx/sites-available/templates-uz-provisioner`:

```nginx
server {
    listen 127.0.0.1:9091;
    server_name _;

    root /opt/templates-uz-provisioner/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/templates-uz-provisioner.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/templates-uz-provisioner /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**Do not** add a `server_name` matching your real domain here, and **do not**
open this port in your firewall — it must only be reachable from
`127.0.0.1`, i.e. from Templates.uz running on the same machine.

### 7. Point Templates.uz at it

In the main Templates.uz `.env`:

```
PROVISIONER_URL=http://127.0.0.1:9091
PROVISIONER_TOKEN=<same value as this program's .env>
```

### 8. Verify

```bash
curl http://127.0.0.1:9091/health
# {"ok":true,"docker":true}
```

## Nginx automation setup (one-time, per server)

`/provision` and `/destroy` write per-subdomain Nginx vhost files and reload
Nginx for you — but this needs a small amount of one-time, least-privilege
setup so the Provisioner's own OS user (not root) can do that safely.

### 1. A directory the Provisioner's OS user owns

```bash
sudo mkdir -p /etc/nginx/conf.d/templates-uz-dynamic
sudo chown templates-uz-provisioner:templates-uz-provisioner /etc/nginx/conf.d/templates-uz-dynamic
```

This is the ONLY place this program ever writes Nginx config — never
`/etc/nginx/conf.d` directly, never `sites-available`.

### 2. Include it from your main Nginx config (once)

Ubuntu's stock `nginx.conf` already includes `/etc/nginx/conf.d/*.conf` inside
its `http {}` block, so the directory above is picked up automatically —
confirm with `grep conf.d /etc/nginx/nginx.conf`. If your setup doesn't
already do this, add manually inside the `http {}` block:

```nginx
include /etc/nginx/conf.d/templates-uz-dynamic/*.conf;
```

### 3. A narrow sudoers rule (only `nginx -t` and reload — nothing else)

```bash
echo 'templates-uz-provisioner ALL=(root) NOPASSWD: /usr/sbin/nginx -t, /bin/systemctl reload nginx' \
  | sudo tee /etc/sudoers.d/templates-uz-provisioner
sudo visudo -c   # always validate after editing sudoers
```

Adjust the `nginx`/`systemctl` paths if `which nginx` / `which systemctl`
differ on your distro. If you'd rather not grant sudo at all (e.g. this
process already runs as root in your setup, which is NOT recommended), set
`PROVISIONER_NGINX_NO_SUDO=1` in `.env` instead.

### 4. The wildcard cert already covers this

No per-subdomain certbot call is needed — dynamic projects reuse the same
`*.templates.uz` wildcard certificate NGINX.md sets up for static projects
(`PROVISIONER_WILDCARD_CERT_DIR`, default
`/etc/letsencrypt/live/templates.uz-wildcard`).

### 5. Verify

Provision a test dynamic project from Templates.uz's admin panel, then:

```bash
cat /etc/nginx/conf.d/templates-uz-dynamic/{subdomain}.conf
curl -I https://{subdomain}.templates.uz
```

If `nginx_configured` came back `false` in the Provisioner's response, check
`nginx_error` (shown on the project's edit page in Templates.uz) — almost
always either step 1 or step 3 above wasn't done yet. Once fixed, use the
"Retry provisioning" action on the project to re-run just the Nginx wiring
(pass `provisionContainer: false` — see `ProjectController::reprovision` on
the Templates.uz side, or just fix the underlying issue and hit `/provision`
again; it's idempotent).

## After provisioning a dynamic project

1. Deploy code into `/var/www/customers/{subdomain}/` (SSH/SFTP/git — however
   you prefer; this program never touches it again after creating it). The
   container serves whatever's there on port 80 (`php:{version}-apache`).
2. If you provisioned a database (`/database` endpoint), plug the returned
   `db_host`/`db_name`/`db_username`/`db_password` into the app's own config
   — Templates.uz shows these on the project's edit page.

Domain/Nginx routing itself is automatic now (see "Nginx automation setup"
above) — nothing manual left here as long as that one-time setup is done.

## Database records

`/database` records which database/user it created for a subdomain in a
small local registry — one JSON file per subdomain under
`PROVISIONER_DB_REGISTRY_DIR` (defaults next to the budget lock file; see
`.env.example`). `/destroy` with `"drop_database": true` drops *whatever
this registry says belongs to that subdomain* — the request body's
`drop_database` flag only controls **whether** to drop a database, never
**which** one, so a caller can't name an arbitrary database to drop. Back
this directory up (or accept that a lost registry just means a future
`/destroy` won't auto-clean the database — the container and Nginx cleanup
are unaffected either way).

## Security notes

- Every subdomain is validated (`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
  before it touches a filesystem path, Docker command, or SQL statement —
  see `src/Subdomain.php`. This program never trusts its caller, even though
  Templates.uz validates too.
- All shell commands run via `proc_open()` with an **argument array**, never
  a concatenated string — PHP does not invoke a shell for array commands, so
  there's no shell-injection surface regardless of input.
- `MysqlProvisioner::drop()` never takes a database/user name from the
  caller at all — it only accepts a subdomain and looks up what (if
  anything) it recorded for that subdomain in its own registry (see
  "Database records" above), so a caller can never name an arbitrary
  database, including another project's `proj_*` database, to drop.
- `NginxClient` only ever writes inside `PROVISIONER_NGINX_CONF_DIR` (a
  directory this program's own OS user owns) and only ever runs `nginx -t`
  and the reload command via a sudoers rule scoped to exactly those two
  commands — it cannot write or reload anything else on the host.
- The Nginx rate-limit zone for dynamic projects is shared across every
  project (keyed by `$host`, one fixed rate) — vanilla Nginx can't vary the
  rate per key without Nginx Plus or OpenResty/Lua. Per-plan rate limits only
  exist for *static* projects (enforced in Laravel, see the main app's
  `AppServiceProvider::configureRateLimiting()`).
- Keep this program's codebase small. If it grows past a few hundred lines,
  that's a sign functionality is creeping in that belongs elsewhere.
