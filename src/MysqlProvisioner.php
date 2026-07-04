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
 */
final class MysqlProvisioner
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private \PDO $pdo;

    public function __construct()
    {
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

        $this->pdo = new \PDO(
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

        $this->pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $this->pdo->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY '{$password}'");
            $this->pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'%'");
            $this->pdo->exec('FLUSH PRIVILEGES');
        } catch (\Throwable $e) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");

            throw new ProvisioningException('MySQL provisioning failed: ' . $e->getMessage());
        }

        return [
            'db_name'     => $dbName,
            'db_username' => $dbUser,
            'db_password' => $password,
            'db_host'     => $host,
        ];
    }

    public function drop(string $dbName, string $dbUsername): void
    {
        if (! preg_match('/^proj_[a-z0-9]+$/', $dbName) || ! preg_match('/^proj_[a-z0-9]+$/', $dbUsername)) {
            // Defense in depth: only ever drop identifiers this class itself
            // could have generated, never whatever a caller happens to send.
            throw new ProvisioningException('Refusing to drop a database/user outside the proj_* naming scheme.');
        }

        $this->pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        $this->pdo->exec("DROP USER IF EXISTS '{$dbUsername}'@'%'");
        $this->pdo->exec('FLUSH PRIVILEGES');
    }

    private function randomString(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
