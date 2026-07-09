<?php

namespace Provisioner;

/**
 * Creates/drops an isolated MySQL database + user for a project, using its
 * OWN database connection (PROVISIONER_DB_*) — separate from Templates.uz's
 * connection, which never has CREATE/GRANT privileges. This connection does,
 * and only this program holds those credentials.
 *
 * Identifiers/passwords are always generated internally from a fixed
 * alphanumeric alphabet — never derived from caller-supplied text — so
 * they're safe to interpolate directly into DDL/DCL statements, which don't
 * support bound parameters for identifiers anyway.
 *
 * Which database/user belongs to which subdomain is tracked in a small local
 * registry (one JSON file per subdomain, see registryPath()) written on
 * provision() and consulted — never the caller — on drop(). A caller only
 * ever gets to say "drop whatever DB this subdomain has, if any"; it can't
 * name an arbitrary proj_* database, so a bug or compromise on the
 * Templates.uz side can't be used to drop a different project's database.
 */
final class MysqlProvisioner
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private ?\PDO $pdo = null;

    /**
     * Connects lazily — drop() only needs a connection when this subdomain
     * actually has a registry entry (see readRegistry()), so a /destroy for
     * a project that never called /database works even on a host where
     * PROVISIONER_DB_* isn't configured at all.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host     = getenv('PROVISIONER_DB_HOST') ?: '127.0.0.1';
        $port     = getenv('PROVISIONER_DB_PORT') ?: '3306';
        $username = getenv('PROVISIONER_DB_USERNAME');
        $password = getenv('PROVISIONER_DB_PASSWORD');

        // No fallback to 'root'/'' — this connection needs CREATE/CREATE
        // USER/GRANT OPTION, so a misconfiguration (unset env var, typo in
        // the PHP-FPM pool config) must fail loudly, not silently try MySQL
        // root with a blank password (which succeeds on some default/dev
        // MySQL installs and would hand this endpoint full instance-wide
        // root instead of the intended scoped user).
        if (! $username) {
            throw new ProvisioningException('PROVISIONER_DB_USERNAME is not set — refusing to fall back to root.');
        }

        return $this->pdo = new \PDO(
            "mysql:host={$host};port={$port}",
            $username,
            $password ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @return array{db_name: string, db_username: string, db_password: string, db_host: string}
     */
    public function provision(Subdomain $subdomain): array
    {
        $suffix   = $this->randomString(12);
        $dbName   = "proj_{$suffix}";
        $dbUser   = "proj_{$suffix}";
        $password = $this->randomString(24);
        $host     = getenv('PROVISIONER_DB_HOST') ?: '127.0.0.1';
        $pdo      = $this->pdo();

        $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $pdo->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$password}'");
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'%'");
            $pdo->exec('FLUSH PRIVILEGES');
        } catch (\Throwable $e) {
            // Also drop the user, not just the database — if CREATE USER succeeded
            // but GRANT then threw, leaving this out orphans a permanent, privilege-
            // less MySQL account on every such failure with no visible trace of it.
            $pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'%'");
            $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");

            throw new ProvisioningException('MySQL provisioning failed: ' . $e->getMessage());
        }

        // Recorded AFTER the database/user actually exist, so a registry
        // entry is never written for something that failed to provision.
        $this->writeRegistry($subdomain, $dbName, $dbUser);

        return [
            'db_name'     => $dbName,
            'db_username' => $dbUser,
            'db_password' => $password,
            'db_host'     => $host,
        ];
    }

    /**
     * Drops whatever database/user this subdomain's own registry entry
     * says was provisioned for it — a caller can ask "does this subdomain
     * have a database to drop?" but can't name the database itself, so it
     * can never point this at a database belonging to a different project.
     * A no-op (not an error) if this subdomain never called provision(), or
     * already had its database dropped — keeps /destroy idempotent.
     */
    public function drop(Subdomain $subdomain): void
    {
        $record = $this->readRegistry($subdomain);

        if ($record === null) {
            return;
        }

        [$dbName, $dbUsername] = $record;

        $pdo = $this->pdo();
        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $pdo->exec("DROP USER IF EXISTS '{$dbUsername}'@'%'");
        $pdo->exec('FLUSH PRIVILEGES');

        $this->deleteRegistry($subdomain);
    }

    private function randomString(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * Directory the per-subdomain "which db/user did we create for this"
     * records live in — defaults alongside the budget lock file, since both
     * are host-local state this process's OS user must own. 0700: these
     * files name every project's database identifiers.
     */
    private function registryDir(): string
    {
        $dir = getenv('PROVISIONER_DB_REGISTRY_DIR')
            ?: rtrim(getenv('PROVISIONER_LOCK_DIR') ?: sys_get_temp_dir(), '/') . '/templates-uz-provisioner-db-registry';

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new ProvisioningException("Could not create database registry directory {$dir}.");
        }

        return rtrim($dir, '/');
    }

    private function registryPath(Subdomain $subdomain): string
    {
        return $this->registryDir() . '/' . $subdomain->value . '.json';
    }

    private function writeRegistry(Subdomain $subdomain, string $dbName, string $dbUsername): void
    {
        $path = $this->registryPath($subdomain);
        $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, json_encode(['db_name' => $dbName, 'db_username' => $dbUsername])) === false) {
            throw new ProvisioningException("Could not write {$tmp}.");
        }

        // Atomic within the same directory, same reasoning as NginxClient's
        // vhost writes: drop() must never see a half-written registry file.
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new ProvisioningException("Could not move {$tmp} into place.");
        }
    }

    /** @return array{0: string, 1: string}|null */
    private function readRegistry(Subdomain $subdomain): ?array
    {
        $path = $this->registryPath($subdomain);

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        // Same proj_* check as before, now applied to OUR OWN records rather
        // than caller input — cheap insurance against a hand-edited or
        // corrupted registry file being used to build a DROP statement.
        if (
            ! is_array($data)
            || ! isset($data['db_name'], $data['db_username'])
            || ! is_string($data['db_name']) || ! preg_match('/^proj_[a-z0-9]+$/', $data['db_name'])
            || ! is_string($data['db_username']) || ! preg_match('/^proj_[a-z0-9]+$/', $data['db_username'])
        ) {
            return null;
        }

        return [$data['db_name'], $data['db_username']];
    }

    private function deleteRegistry(Subdomain $subdomain): void
    {
        @unlink($this->registryPath($subdomain));
    }
}
