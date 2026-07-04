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
- `POST /destroy`   — removes the container (and DB, if `db_name`/`db_username`
  are given). **Never** deletes `/var/www/customers/{subdomain}/` — that's
  code you deployed by hand, so a project deletion in Templates.uz can't
  destroy it out from under you.
- `GET  /stats?subdomain=...` — CPU/RAM usage for a running container.

It deliberately does **not** touch Nginx, domains, or SSL — you wire those up
yourself per project (see "After provisioning" below).

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

## After provisioning a dynamic project

The Provisioner only gets you an empty, resource-limited environment. To go
live:

1. Deploy code into `/var/www/customers/{subdomain}/` (SSH/SFTP/git — however
   you prefer; this program never touches it again after creating it).
2. Point DNS/Nginx at the container. Simplest approach — add an Nginx
   `location`/`server` block that proxies the subdomain to the container's
   port on the `templates-uz-projects` Docker network
   (`docker inspect templates-uz-project-{subdomain}` to find its IP), then
   get a cert with `certbot --nginx -d {subdomain}.templates.uz`.
3. If you provisioned a database (`/database` endpoint), plug the returned
   `db_host`/`db_name`/`db_username`/`db_password` into the app's own config
   — Templates.uz shows these on the project's edit page.

## Security notes

- Every subdomain is validated (`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
  before it touches a filesystem path, Docker command, or SQL statement —
  see `src/Subdomain.php`. This program never trusts its caller, even though
  Templates.uz validates too.
- All shell commands run via `proc_open()` with an **argument array**, never
  a concatenated string — PHP does not invoke a shell for array commands, so
  there's no shell-injection surface regardless of input.
- `MysqlProvisioner::drop()` refuses to drop anything outside the `proj_*`
  naming scheme it generates itself — defense in depth against a caller
  somehow passing an arbitrary database name.
- Keep this program's codebase small. If it grows past a few hundred lines,
  that's a sign functionality is creeping in that belongs elsewhere.
