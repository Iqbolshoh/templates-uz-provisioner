<?php

namespace Provisioner;

/**
 * A single validated helper used by every other class here — nothing in this
 * program ever builds a directory path, container name, or SQL identifier
 * from a subdomain string that hasn't passed through this first. Templates.uz
 * validates subdomains too, but this program must never trust its caller;
 * it's the one thing on the server with root-equivalent (Docker/MySQL) power.
 */
final class Subdomain
{
    private function __construct(public readonly string $value)
    {
    }

    public static function parse(mixed $raw): self
    {
        if (! is_string($raw) || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $raw)) {
            throw new ProvisioningException('Invalid subdomain.');
        }

        return new self($raw);
    }

    public function containerName(): string
    {
        return 'templates-uz-project-' . $this->value;
    }

    public function linuxPath(): string
    {
        return rtrim(getenv('PROVISIONER_CUSTOMERS_ROOT') ?: '/var/www/customers', '/') . '/' . $this->value;
    }
}
