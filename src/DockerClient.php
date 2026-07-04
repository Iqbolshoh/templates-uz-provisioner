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

        $this->assertWithinBudget($subdomain, $cpuPercent, $ramMb);

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
        // claim its name.
        ProcessRunner::run(['docker', 'rm', '-f', $finalName]);
        ProcessRunner::run(['docker', 'rename', $tempName, $finalName]);

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
     * across every project this Provisioner manages past the configured
     * host budget (PROVISIONER_MAX_TOTAL_RAM_MB / _CPU_PERCENT — leave unset
     * or 0 to disable). Sized correctly, this is what actually prevents one
     * server from being oversold across many "dynamic" projects; see
     * server.md section 0 for how to size these for a given machine.
     *
     * @throws ProvisioningException
     */
    private function assertWithinBudget(Subdomain $subdomain, ?int $cpuPercent, ?int $ramMb): void
    {
        $budgetRamMb  = (float) (getenv('PROVISIONER_MAX_TOTAL_RAM_MB') ?: 0);
        $budgetCpuPct = (float) (getenv('PROVISIONER_MAX_TOTAL_CPU_PERCENT') ?: 0);

        if ($budgetRamMb <= 0 && $budgetCpuPct <= 0) {
            return; // no budget configured — admission control disabled
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

    /** @return array{ram_mb: float, cpu_percent: float} */
    private function currentlyAllocated(?string $excludeContainerName = null): array
    {
        $list = ProcessRunner::run(['docker', 'ps', '-a', '--filter', 'label=' . self::LABEL, '--format', '{{.Names}}']);
        $names = array_filter(array_map('trim', explode("\n", trim($list['stdout']))));

        $totalRamBytes = 0;
        $totalNanoCpus = 0;

        foreach ($names as $name) {
            if ($name === $excludeContainerName) {
                continue; // this project's own (about-to-be-replaced) container doesn't count against itself
            }

            $inspect = ProcessRunner::run(['docker', 'inspect', '--format', '{{.HostConfig.Memory}}|{{.HostConfig.NanoCpus}}', $name]);

            if ($inspect['exitCode'] !== 0) {
                continue;
            }

            [$memBytes, $nanoCpus] = array_pad(explode('|', trim($inspect['stdout'])), 2, '0');
            $totalRamBytes += (int) $memBytes;
            $totalNanoCpus += (int) $nanoCpus;
        }

        return [
            'ram_mb'      => $totalRamBytes / 1024 / 1024,
            'cpu_percent' => $totalNanoCpus / 1_000_000_000 * 100,
        ];
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
