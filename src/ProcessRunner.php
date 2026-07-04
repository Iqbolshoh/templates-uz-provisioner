<?php

namespace Provisioner;

/**
 * Runs a command from an argument array via proc_open — PHP does NOT pass
 * array commands through a shell (no /bin/sh -c), so there is no shell
 * injection surface here regardless of what ends up in the arguments, as
 * long as each element is a single argument (never a concatenated string).
 *
 * Every command is wrapped in GNU `timeout` (coreutils, present on any Ubuntu
 * box) so a hung `docker` command actually gets killed. stream_set_timeout()
 * alone does NOT do this — it only affects how long a single blocking read
 * waits for more data; proc_close() would otherwise block forever on a
 * process that never exits, and with only a handful of PHP-FPM workers
 * (see README.md) a couple of hung requests would take the whole Provisioner
 * down.
 */
final class ProcessRunner
{
    /** @return array{exitCode: int, stdout: string, stderr: string} */
    public static function run(array $args, int $timeoutSeconds = 30): array
    {
        $wrapped = ['timeout', '--kill-after=5', "{$timeoutSeconds}s", ...$args];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($wrapped, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new ProvisioningException('Failed to start process: ' . implode(' ', $args));
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // `timeout` exits 124 specifically when it had to kill the child —
        // surface that distinctly rather than a bare non-zero exit code.
        if ($exitCode === 124) {
            throw new ProvisioningException('Command timed out after ' . $timeoutSeconds . 's: ' . implode(' ', $args));
        }

        return ['exitCode' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
