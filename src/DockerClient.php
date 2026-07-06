<?php

namespace Provisioner;

/**
 * Creates/destroys a resource-limited Docker container for a project,
 * bind-mounted (read-write) to /var/www/customers/{subdomain}/ — the admin
 * deploys code into that directory manually (SSH/SFTP/git); this class never
 * touches its contents. Domain/Nginx routing is also manual (see README.md)
 * — this class only makes the container exist and reachable on the shared
 * Docker network, it doesn't expose it to the internet itself.
 */
final class DockerClient
{
    private const NETWORK = 'templates-uz-projects';
    private const LABEL   = 'templates-uz.subdomain';

    public function isAvailable(): bool
    {
        try {
            return ProcessRunner::run(['docker', 'version', '--format', '{{.Server.Version}}'])['exitCode'] === 0;
        } catch (\Throwable) {
            // docker binary missing entirely, or proc_open itself couldn't spawn —
            // both mean "not available", not "the health check is broken".
            return false;
        }
    }

    /**
     * @return array{linux_path: string, container_id: string, container_name: string}
     * @throws ProvisioningException
     */
    public function provision(
        Subdomain $subdomain,
        ?int $cpuPercent,
        ?int $ramMb,
        ?int $processLimit,
        ?string $phpVersion,
    ): array {
        if (! $this->isAvailable()) {
            throw new ProvisioningException('Docker is not available on this host.');
        }

        // Budget check + container creation must run under a single host-wide
        // lock: PHP-FPM serves several /provision requests concurrently (see
        // README's pm.max_children), and without this, two requests could
        // each read the budget as "not yet exceeded" before either container
        // exists, then both proceed — together blowing past the configured
        // limit. Provisioning is an infrequent, admin-triggered action, so
        // serializing it host-wide is a fine trade for correctness here.
        return $this->withBudgetLock(function () use ($subdomain, $cpuPercent, $ramMb, $processLimit, $phpVersion) {
            $this->assertWithinBudget($subdomain, $cpuPercent, $ramMb);

            return $this->doProvision($subdomain, $cpuPercent, $ramMb, $processLimit, $phpVersion);
        });
    }

    /**
     * @return array{linux_path: string, container_id: string, container_name: string}
     * @throws ProvisioningException
     */
    private function doProvision(
        Subdomain $subdomain,
        ?int $cpuPercent,
        ?int $ramMb,
        ?int $processLimit,
        ?string $phpVersion,
    ): array {
        $path = $subdomain->linuxPath();

        if (! is_dir($path) && ! mkdir($path, 0755, true)) {
            throw new ProvisioningException("Could not create {$path}.");
        }

        $this->ensureNetwork();

        // Re-provisioning (e.g. after a plan change) builds the replacement
        // under a temporary name FIRST and only removes the old container
        // once the new one has actually started — a failed `docker run`
        // here leaves the working container untouched instead of taking the
        // site down. The directory is never touched either way; the admin's
        // deployed code stays put.
        $finalName = $subdomain->containerName();
        $tempName  = $finalName . '-new-' . bin2hex(random_bytes(4));

        $args = [
            'docker', 'run', '-d',
            '--name', $tempName,
            '--network', self::NETWORK,
            '--restart', 'unless-stopped',
            '-v', "{$path}:/var/www/html",
            '--label', self::LABEL . '=' . $subdomain->value,
        ];

        // Deliberately checking !== null, not truthiness — a caller-supplied
        // 0 or negative value must be rejected outright (see index.php's
        // bounds validation), never silently treated as "no limit."
        // PHP's `if ($ramMb)` would treat 0 as falsy and skip the flag
        // entirely, handing out an UNLIMITED container instead of a
        // zero/blocked one — the opposite of a fail-safe default.
        if ($ramMb !== null) {
            $args[] = '--memory';
            $args[] = "{$ramMb}m";
        }
        if ($cpuPercent !== null) {
            $args[] = '--cpus';
            $args[] = number_format($cpuPercent / 100, 2, '.', '');
        }
        if ($processLimit !== null) {
            $args[] = '--pids-limit';
            $args[] = (string) $processLimit;
        }

        $args[] = 'php:' . ($phpVersion ?: '8.3') . '-apache';

        $result = ProcessRunner::run($args, timeoutSeconds: 60);

        if ($result['exitCode'] !== 0) {
            // New container failed — clean up its own leftovers, but the
            // OLD container (if any) was never touched.
            ProcessRunner::run(['docker', 'rm', '-f', $tempName]);
            $this->log('provision_failed', $subdomain, $result['stderr']);

            throw new ProvisioningException('docker run failed: ' . trim($result['stderr']));
        }

        $containerId = trim($result['stdout']);

        // New container is up — now it's safe to remove the old one and
        // claim its name. Removing the old one is best-effort (it may not
        // exist yet on a first provision), but the rename is NOT: every
        // other method here (containerIp/stats/destroy/re-provision) looks
        // the container up by $finalName, so a silently-failed rename would
        // leave a running container that's unreachable through this API —
        // it keeps consuming resources but Nginx/health checks can never
        // find it again. Fail loudly and clean it up instead.
        ProcessRunner::run(['docker', 'rm', '-f', $finalName]);
        $rename = ProcessRunner::run(['docker', 'rename', $tempName, $finalName]);

        if ($rename['exitCode'] !== 0) {
            ProcessRunner::run(['docker', 'rm', '-f', $tempName]);
            $this->log('provision_rename_failed', $subdomain, trim($rename['stderr']));

            throw new ProvisioningException('Container started but docker rename failed: ' . trim($rename['stderr']));
        }

        $this->log('provisioned', $subdomain, "container={$containerId}");

        return [
            'linux_path'     => $path,
            'container_id'   => $containerId,
            'container_name' => $finalName,
        ];
    }

    public function destroy(Subdomain $subdomain): void
    {
        $this->destroyContainer($subdomain);
        $this->log('destroyed', $subdomain);
        // Deliberately NOT deleting $subdomain->linuxPath() — that's the
        // admin's deployed code. Removing a project's container should never
        // silently destroy files the admin put there by hand.
    }

    /**
     * The container's IP on the `templates-uz-projects` bridge network — the
     * address Nginx (running on the host, not in Docker) proxies to. Null if
     * the container doesn't exist or isn't running.
     */
    public function containerIp(Subdomain $subdomain): ?string
    {
        $result = ProcessRunner::run([
            'docker', 'inspect', '--format',
            '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}',
            $subdomain->containerName(),
        ]);

        if ($result['exitCode'] !== 0) {
            return null;
        }

        $ip = trim($result['stdout']);

        return $ip !== '' ? $ip : null;
    }

    /** @return array{cpu_percent: float, ram_mb: float}|null */
    public function stats(Subdomain $subdomain): ?array
    {
        $result = ProcessRunner::run([
            'docker', 'stats', $subdomain->containerName(), '--no-stream', '--format', '{{.CPUPerc}}|{{.MemUsage}}',
        ]);

        if ($result['exitCode'] !== 0 || trim($result['stdout']) === '') {
            return null;
        }

        [$cpu, $mem] = array_pad(explode('|', trim($result['stdout'])), 2, '');
        [$ramUsed]   = array_pad(explode(' / ', $mem), 1, '0MiB');

        return [
            'cpu_percent' => (float) rtrim(trim($cpu), '%'),
            'ram_mb'      => $this->parseMemToMb(trim($ramUsed)),
        ];
    }

    /**
     * Refuses to provision if doing so would push total committed RAM/CPU
     * across every project this Provisioner manages past the host budget.
     *
     * PROVISIONER_MAX_TOTAL_RAM_MB / _CPU_PERCENT, if set to a positive
     * number, are used as-is (see server.md section 0 for how to size these
     * for a given machine). If left unset — or explicitly set to -1 to
     * really disable this check — the budget is auto-detected from the
     * host's real capacity (75% of total RAM / CPU cores, reserving the rest
     * for the OS + the main site's own stack) so a freshly deployed server
     * is still protected against oversell even before anyone tunes these by
     * hand. Set to -1 to opt all the way out (no admission control at all).
     *
     * @throws ProvisioningException
     */
    private function assertWithinBudget(Subdomain $subdomain, ?int $cpuPercent, ?int $ramMb): void
    {
        $envRamMb  = getenv('PROVISIONER_MAX_TOTAL_RAM_MB');
        $envCpuPct = getenv('PROVISIONER_MAX_TOTAL_CPU_PERCENT');

        if ($envRamMb !== false && (float) $envRamMb < 0 && $envCpuPct !== false && (float) $envCpuPct < 0) {
            return; // explicitly disabled via -1/-1
        }

        $detected     = $this->detectHostBudget();
        $budgetRamMb  = ($envRamMb !== false && (float) $envRamMb > 0) ? (float) $envRamMb : $detected['ram_mb'];
        $budgetCpuPct = ($envCpuPct !== false && (float) $envCpuPct > 0) ? (float) $envCpuPct : $detected['cpu_percent'];

        if ($budgetRamMb <= 0 && $budgetCpuPct <= 0) {
            return; // detection failed (no /proc/meminfo, no nproc) and nothing was configured — nothing safe to enforce
        }

        $current = $this->currentlyAllocated(excludeContainerName: $subdomain->containerName());

        $projectedRamMb  = $current['ram_mb'] + ($ramMb ?? 0);
        $projectedCpuPct = $current['cpu_percent'] + ($cpuPercent ?? 0);

        if ($budgetRamMb > 0 && $projectedRamMb > $budgetRamMb) {
            throw new ProvisioningException(sprintf(
                'Refusing to provision: this would commit %.0f MB RAM total, exceeding the configured host budget of %.0f MB (%.0f MB already committed to other projects).',
                $projectedRamMb, $budgetRamMb, $current['ram_mb'],
            ));
        }

        if ($budgetCpuPct > 0 && $projectedCpuPct > $budgetCpuPct) {
            throw new ProvisioningException(sprintf(
                'Refusing to provision: this would commit %.0f%% CPU total, exceeding the configured host budget of %.0f%% (%.0f%% already committed to other projects).',
                $projectedCpuPct, $budgetCpuPct, $current['cpu_percent'],
            ));
        }
    }

    /**
     * Auto-detected fallback budget when PROVISIONER_MAX_TOTAL_RAM_MB /
     * _CPU_PERCENT aren't explicitly set: 75% of the host's real RAM/CPU,
     * reserving the remaining 25% for the OS + the main site's own stack
     * (Nginx, MySQL, Redis, PHP-FPM, this Provisioner, the Docker daemon
     * itself — see server.md section 0 for the reasoning behind that split
     * on the reference server). Returns zeros if detection fails (e.g.
     * /proc/meminfo or `nproc` unavailable), which the caller treats as
     * "nothing safe to enforce" rather than guessing.
     *
     * @return array{ram_mb: float, cpu_percent: float}
     */
    private function detectHostBudget(): array
    {
        $reserve = 0.75; // commit at most 75% of host capacity to dynamic projects

        $meminfo = @file_get_contents('/proc/meminfo');
        $totalRamMb = ($meminfo !== false && preg_match('/MemTotal:\s+(\d+)\s*kB/', $meminfo, $m))
            ? ((float) $m[1]) / 1024
            : 0.0;

        $nproc = ProcessRunner::run(['nproc']);
        $cpuCount = ($nproc['exitCode'] === 0 && is_numeric(trim($nproc['stdout'])))
            ? (int) trim($nproc['stdout'])
            : 0;

        return [
            'ram_mb'      => $totalRamMb > 0 ? floor($totalRamMb * $reserve) : 0.0,
            'cpu_percent' => $cpuCount > 0 ? floor($cpuCount * 100 * $reserve) : 0.0,
        ];
    }

    /** @return array{ram_mb: float, cpu_percent: float} */
    private function currentlyAllocated(?string $excludeContainerName = null): array
    {
        $list = ProcessRunner::run(['docker', 'ps', '-a', '--filter', 'label=' . self::LABEL, '--format', '{{.Names}}']);
        $names = array_values(array_filter(array_map('trim', explode("\n", trim($list['stdout']))), fn($n) => $n !== '' && $n !== $excludeContainerName));

        if ($names === []) {
            return ['ram_mb' => 0.0, 'cpu_percent' => 0.0];
        }

        // One batched `docker inspect` call for every tracked container
        // instead of one process spawn per container — this list only grows
        // as more dynamic projects are provisioned, and every single
        // /provision request pays this cost before it's even allowed to
        // proceed, so an N+1 pattern here gets slower the more this host
        // hosts.
        $inspect = ProcessRunner::run(['docker', 'inspect', '--format', '{{.HostConfig.Memory}}|{{.HostConfig.NanoCpus}}', ...$names]);

        // Parse whatever came back on stdout regardless of exit code — if a
        // container disappeared between the `docker ps` above and this call,
        // `docker inspect` exits non-zero for the WHOLE batch even though
        // every other container's line is still valid output. Bailing out
        // on a non-zero exit code here would silently undercount every
        // OTHER project too, not just the missing one.
        $totalRamBytes = 0;
        $totalNanoCpus = 0;

        foreach (array_filter(explode("\n", trim($inspect['stdout']))) as $line) {
            [$memBytes, $nanoCpus] = array_pad(explode('|', trim($line)), 2, '0');
            $totalRamBytes += (int) $memBytes;
            $totalNanoCpus += (int) $nanoCpus;
        }

        return [
            'ram_mb'      => $totalRamBytes / 1024 / 1024,
            'cpu_percent' => $totalNanoCpus / 1_000_000_000 * 100,
        ];
    }

    /**
     * Serializes the budget-check-then-provision critical section across
     * every PHP-FPM worker via a host-wide advisory lock (flock), so two
     * concurrent /provision calls can never both pass the budget check
     * before either container exists. Held for the full provision, not just
     * the check, since that's the only way to make the check-then-act
     * sequence atomic with a simple file lock.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws ProvisioningException
     */
    private function withBudgetLock(callable $fn)
    {
        $lockPath = rtrim(getenv('PROVISIONER_LOCK_DIR') ?: sys_get_temp_dir(), '/') . '/templates-uz-provisioner-budget.lock';
        $handle   = @fopen($lockPath, 'c');

        if ($handle === false) {
            throw new ProvisioningException("Could not open lock file {$lockPath}.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ProvisioningException('Could not acquire the provisioning lock.');
            }

            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function destroyContainer(Subdomain $subdomain): void
    {
        // Best-effort — "no such container" is a normal outcome, not an error.
        ProcessRunner::run(['docker', 'rm', '-f', $subdomain->containerName()]);
    }

    private function ensureNetwork(): void
    {
        // Best-effort — "already exists" is the expected outcome after the first project.
        ProcessRunner::run(['docker', 'network', 'create', self::NETWORK]);
    }

    private function parseMemToMb(string $value): float
    {
        if (preg_match('/([\d.]+)\s*(GiB|MiB|KiB|GB|MB|KB|B)/i', $value, $m)) {
            $num  = (float) $m[1];
            $unit = strtolower($m[2]);

            return match (true) {
                str_starts_with($unit, 'g') => $num * 1024,
                str_starts_with($unit, 'k') => $num / 1024,
                default => $num,
            };
        }

        return 0.0;
    }

    /**
     * Minimal audit trail — this class has root-equivalent Docker access, so
     * every provision/destroy is logged to the web server's error log (picked
     * up by `journalctl`/whatever log shipper you already have) with no
     * extra moving parts. Not a substitute for a real audit system, but
     * better than nothing for "what did this token do, and when."
     */
    private function log(string $action, Subdomain $subdomain, string $detail = ''): void
    {
        error_log(sprintf('[provisioner] %s subdomain=%s %s', $action, $subdomain->value, $detail));
    }
}
