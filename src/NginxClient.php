<?php

namespace Provisioner;

/**
 * Automates the Nginx vhost + rate-limit wiring for a dynamic project's
 * container, so "After provisioning" no longer requires the admin to hand-
 * write a server block (see README.md). Reuses the existing wildcard
 * certificate (*.templates.uz) that NGINX.md already sets up for static
 * projects — no per-subdomain certbot call needed.
 *
 * Least-privilege by design: this class only ever writes inside
 * PROVISIONER_NGINX_CONF_DIR (a directory the Provisioner's OS user owns,
 * NOT /etc/nginx/conf.d directly) and only ever runs `nginx -t` / a reload
 * command via a narrow, documented sudoers rule — see README.md "Nginx
 * automation setup." It never touches any other Nginx config.
 */
final class NginxClient
{
    private const ZONE_FILENAME = '00-dynamic-projects-zone.conf';

    public function isAvailable(): bool
    {
        return is_dir($this->confDir()) && is_writable($this->confDir());
    }

    /**
     * Writes (or replaces) the subdomain's server block, proxying to the
     * container's IP on the `templates-uz-projects` bridge network, tests
     * the full Nginx config, and reloads. Rolls back (deletes the file it
     * just wrote) if the test fails, so one bad vhost can never take down
     * every other site Nginx serves.
     *
     * @throws ProvisioningException
     */
    public function provision(Subdomain $subdomain, string $containerIp, int $containerPort = 80): void
    {
        $this->assertAvailable();
        $this->ensureZoneFile();

        $domain = $this->projectsDomain();
        $certDir = rtrim(getenv('PROVISIONER_WILDCARD_CERT_DIR') ?: '/etc/letsencrypt/live/templates.uz-wildcard', '/');
        $zoneFile = $this->confDir() . '/' . self::ZONE_FILENAME;

        $conf = <<<NGINX
        # Managed by templates-uz-provisioner — do not edit by hand, it will
        # be overwritten on the next re-provision. Delete via /destroy instead.
        server {
            listen 443 ssl;
            server_name {$subdomain->value}.{$domain};

            # Shared rate-limit zone keyed by \$host (== this subdomain, since
            # each vhost's server_name is unique — see {$zoneFile}).
            # NOTE: vanilla Nginx zones have one fixed rate for every key, so
            # this is the SAME limit for every dynamic project regardless of
            # plan — not per-plan tunable without Nginx Plus or OpenResty/Lua.
            limit_req zone=dynamic_projects burst=50 nodelay;

            location / {
                proxy_pass http://{$containerIp}:{$containerPort};
                proxy_set_header Host \$host;
                proxy_set_header X-Real-IP \$remote_addr;
                proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto \$scheme;
            }

            ssl_certificate     {$certDir}/fullchain.pem;
            ssl_certificate_key {$certDir}/privkey.pem;
        }

        NGINX;

        $this->writeTestAndReload($this->vhostPath($subdomain), $conf, $subdomain, 'provision');
    }

    /**
     * Removes the subdomain's server block. Best-effort by design — callers
     * (ProjectController::destroy via /destroy) proceed with removing the
     * Project row regardless of whether Nginx cleanup succeeds.
     */
    public function destroy(Subdomain $subdomain): void
    {
        $path = $this->vhostPath($subdomain);

        if (! file_exists($path)) {
            return;
        }

        if (! @unlink($path)) {
            $this->log('destroy_failed', $subdomain, "could not unlink {$path}");

            return;
        }

        $test = ProcessRunner::run($this->sudoWrap(['nginx', '-t']));
        if ($test['exitCode'] !== 0) {
            $this->log('destroy_reload_skipped', $subdomain, 'nginx -t failed after removal: ' . trim($test['stderr']));

            return;
        }

        ProcessRunner::run($this->sudoWrap($this->reloadCommand()));
        $this->log('destroyed', $subdomain);
    }

    public function domainFor(Subdomain $subdomain): string
    {
        return $subdomain->value . '.' . $this->projectsDomain();
    }

    private function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new ProvisioningException(
                "Nginx automation is not set up: {$this->confDir()} does not exist or is not writable by this process. " .
                'See README.md "Nginx automation setup" — the container was still created successfully; ' .
                'wire up the domain manually for now.',
            );
        }
    }

    /**
     * Creates the shared `limit_req_zone` definition once, the first time
     * any dynamic project is provisioned. The rate is a single value shared
     * by every dynamic project's subdomain (see the caveat in provision()'s
     * generated comment) — defaults to PROJECTS_RATE_PER_MINUTE's own
     * default (300) so it matches the static-project rate limit out of the
     * box; override with PROVISIONER_DYNAMIC_RATE_PER_MINUTE.
     *
     * Keyed by `$host`, a real built-in Nginx variable, rather than a
     * hand-rolled `$subdomain` — Nginx only recognizes variables that are
     * either built in or created via `map`/`set`/etc.; referencing an
     * undeclared name here makes `nginx -t` fail with `unknown "..."
     * variable` on every reload, which would break Nginx automation for
     * every dynamic project, not just the one being (re)provisioned.
     */
    private function ensureZoneFile(): void
    {
        $path = $this->confDir() . '/' . self::ZONE_FILENAME;

        if (file_exists($path)) {
            return;
        }

        $ratePerMinute = (int) (getenv('PROVISIONER_DYNAMIC_RATE_PER_MINUTE') ?: 300);
        $written = @file_put_contents(
            $path,
            "# Managed by templates-uz-provisioner — shared rate-limit zone for every dynamic project.\n" .
            "limit_req_zone \$host zone=dynamic_projects:10m rate={$ratePerMinute}r/m;\n",
        );

        if ($written === false) {
            throw new ProvisioningException("Could not write {$path}.");
        }
    }

    private function writeTestAndReload(string $path, string $conf, Subdomain $subdomain, string $action): void
    {
        // Captured BEFORE the rename below overwrites it — needed to restore
        // (not just delete) on rollback when this is a re-provision of an
        // already-working vhost.
        $previous = file_exists($path) ? @file_get_contents($path) : false;

        $tmpPath = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmpPath, $conf) === false) {
            throw new ProvisioningException("Could not write {$tmpPath}.");
        }

        // Atomic within the same directory — Nginx never sees a half-written file.
        if (! @rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new ProvisioningException("Could not move {$tmpPath} into place.");
        }

        $test = ProcessRunner::run($this->sudoWrap(['nginx', '-t']));

        if ($test['exitCode'] !== 0) {
            // Roll back. On a first-time provision there's no previous file, so
            // delete the broken one. On a RE-provision (plan/IP change against a
            // subdomain that already had a working vhost), restore the prior
            // content instead of unlinking — deleting here would leave Nginx
            // running fine on its current in-memory config for now, but silently
            // drop this subdomain's routing on the next UNRELATED reload (cert
            // renewal, another project's provision) once it re-reads from disk.
            if ($previous !== false) {
                @file_put_contents($path, $previous);
            } else {
                @unlink($path);
            }
            $this->log($action . '_failed', $subdomain, trim($test['stderr']));

            throw new ProvisioningException('Generated Nginx config is invalid, rolled back: ' . trim($test['stderr']));
        }

        $reload = ProcessRunner::run($this->sudoWrap($this->reloadCommand()));

        if ($reload['exitCode'] !== 0) {
            throw new ProvisioningException('Nginx config is valid but reload failed: ' . trim($reload['stderr']));
        }

        $this->log($action, $subdomain, "vhost={$path}");
    }

    /**
     * Prefixes with `sudo` unless PROVISIONER_NGINX_NO_SUDO=1 (e.g. the
     * Provisioner already runs as root in some deployments, or the reload
     * command is wrapped differently) — see README.md for the narrow
     * sudoers rule this expects (`nginx -t` and the reload command only).
     */
    private function sudoWrap(array $args): array
    {
        if (getenv('PROVISIONER_NGINX_NO_SUDO') === '1') {
            return $args;
        }

        return ['sudo', '-n', ...$args];
    }

    private function reloadCommand(): array
    {
        $custom = getenv('PROVISIONER_NGINX_RELOAD_COMMAND');

        return $custom ? explode(' ', $custom) : ['systemctl', 'reload', 'nginx'];
    }

    private function confDir(): string
    {
        return rtrim(getenv('PROVISIONER_NGINX_CONF_DIR') ?: '/etc/nginx/conf.d/templates-uz-dynamic', '/');
    }

    private function projectsDomain(): string
    {
        return getenv('PROVISIONER_PROJECTS_DOMAIN') ?: 'templates.uz';
    }

    private function vhostPath(Subdomain $subdomain): string
    {
        return $this->confDir() . '/' . $subdomain->value . '.conf';
    }

    private function log(string $action, Subdomain $subdomain, string $detail = ''): void
    {
        error_log(sprintf('[provisioner:nginx] %s subdomain=%s %s', $action, $subdomain->value, $detail));
    }
}
