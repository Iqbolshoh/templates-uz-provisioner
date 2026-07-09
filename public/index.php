<?php

/**
 * Templates.uz Provisioner — a deliberately tiny, standalone program.
 *
 * This is the ONLY thing on the server allowed to touch Docker and create
 * MySQL databases/users. It is NOT part of the Templates.uz Laravel app, has
 * no Composer dependencies (plain `require`s below, no autoloader needed),
 * and should run under its own OS user (in the "docker" group, with its own
 * MySQL admin credentials) behind its own PHP-FPM pool + an Nginx server
 * block bound to 127.0.0.1 only — never exposed to the public internet.
 * Templates.uz talks to it over HTTP with a shared-secret token.
 *
 * See README.md for the full deployment setup.
 *
 * Endpoints:
 *   GET  /health    — no auth; liveness + Docker availability check
 *   POST /provision — create/replace a project's container
 *   POST /database  — create a project's MySQL database + user
 *   POST /destroy   — remove a project's container (and DB, if given)
 */

declare(strict_types=1);

require __DIR__ . '/../src/ProvisioningException.php';
require __DIR__ . '/../src/Subdomain.php';
require __DIR__ . '/../src/ProcessRunner.php';
require __DIR__ . '/../src/DockerClient.php';
require __DIR__ . '/../src/MysqlProvisioner.php';
require __DIR__ . '/../src/NginxClient.php';

use Provisioner\DockerClient;
use Provisioner\MysqlProvisioner;
use Provisioner\NginxClient;
use Provisioner\ProvisioningException;
use Provisioner\Subdomain;

header('Content-Type: application/json');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function requireAuth(): void
{
    $expected = getenv('PROVISIONER_TOKEN');
    $given    = $_SERVER['HTTP_X_PROVISIONER_TOKEN'] ?? '';

    if (! $expected || ! hash_equals($expected, $given)) {
        respond(401, ['error' => 'Unauthorized']);
    }
}

function jsonBody(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);

    return is_array($data) ? $data : [];
}

const SUPPORTED_PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];

/**
 * Validates and returns an optional bounded integer from the request body,
 * or null if the key wasn't sent at all. A caller-supplied 0/negative/absurd
 * value is a hard error (422), never silently clamped or ignored — resource
 * limits are security boundaries, so a malformed request must fail loudly
 * rather than fall back to "no limit."
 */
function optionalBoundedInt(array $body, string $key, int $min, int $max): ?int
{
    if (! isset($body[$key]) || $body[$key] === '' || $body[$key] === null) {
        return null;
    }

    $value = filter_var($body[$key], FILTER_VALIDATE_INT);

    if ($value === false || $value < $min || $value > $max) {
        respond(422, ['error' => "\"{$key}\" must be an integer between {$min} and {$max}."]);
    }

    return $value;
}

function optionalPhpVersion(array $body): ?string
{
    if (empty($body['php_version'])) {
        return null;
    }

    if (! in_array($body['php_version'], SUPPORTED_PHP_VERSIONS, true)) {
        respond(422, ['error' => '"php_version" must be one of: ' . implode(', ', SUPPORTED_PHP_VERSIONS)]);
    }

    return $body['php_version'];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docker = new DockerClient();

try {
    if ($method === 'GET' && $path === '/health') {
        respond(200, ['ok' => true, 'docker' => $docker->isAvailable()]);
    }

    requireAuth();

    if ($method === 'POST' && $path === '/provision') {
        $body      = jsonBody();
        $subdomain = Subdomain::parse($body['subdomain'] ?? null);

        $result = $docker->provision(
            subdomain: $subdomain,
            // Bounds are generous (not tied to any one host's real capacity)
            // — the actual "don't oversell this machine" enforcement is
            // PROVISIONER_MAX_TOTAL_RAM_MB / _CPU_PERCENT in DockerClient,
            // sized per-host (see server.md section 0). These just reject
            // obviously-broken input (negative, zero, absurd) before it
            // reaches `docker run`.
            cpuPercent: optionalBoundedInt($body, 'cpu_percent', min: 1, max: 800),
            ramMb: optionalBoundedInt($body, 'ram_mb', min: 32, max: 65536),
            processLimit: optionalBoundedInt($body, 'process_limit', min: 1, max: 10000),
            phpVersion: optionalPhpVersion($body),
        );

        // Nginx wiring is best-effort from this endpoint's point of view —
        // the container is already up and usable even if the vhost step
        // fails (e.g. Nginx automation isn't set up on this host yet), so a
        // failure here is surfaced in the response, not thrown, and never
        // rolls back the container itself.
        $nginx = new NginxClient();
        $result['domain']           = $nginx->domainFor($subdomain);
        $result['nginx_configured'] = false;

        $containerIp = $docker->containerIp($subdomain);

        if ($containerIp === null) {
            $result['nginx_error'] = 'Could not determine the container IP address.';
        } else {
            try {
                $nginx->provision($subdomain, $containerIp);
                $result['nginx_configured'] = true;
            } catch (ProvisioningException $e) {
                $result['nginx_error'] = $e->getMessage();
            }
        }

        respond(200, $result);
    }

    if ($method === 'POST' && $path === '/database') {
        $body      = jsonBody();
        $subdomain = Subdomain::parse($body['subdomain'] ?? null);

        $result = (new MysqlProvisioner())->provision($subdomain);
        respond(200, $result);
    }

    if ($method === 'POST' && $path === '/destroy') {
        $body      = jsonBody();
        $subdomain = Subdomain::parse($body['subdomain'] ?? null);

        $docker->destroy($subdomain);
        (new NginxClient())->destroy($subdomain);

        // Which database/user (if any) to drop is looked up from this
        // program's own registry, never taken from the request body — see
        // MysqlProvisioner::drop(). "drop_database" only controls WHETHER to
        // drop it, not WHICH one, so a caller can never point this at a
        // different project's database.
        if (! empty($body['drop_database'])) {
            (new MysqlProvisioner())->drop($subdomain);
        }

        respond(200, ['ok' => true]);
    }

    if ($method === 'GET' && $path === '/stats') {
        $subdomain = Subdomain::parse($_GET['subdomain'] ?? null);
        $stats     = $docker->stats($subdomain);

        respond($stats ? 200 : 404, $stats ?? ['error' => 'Container not found or not running.']);
    }

    respond(404, ['error' => 'Not found.']);
} catch (ProvisioningException $e) {
    // These are always our own, deliberately-worded messages (bad subdomain,
    // over budget, docker unavailable, ...) — safe to return as-is.
    respond(422, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Anything else (a raw PDOException, TypeError, ...) could leak internal
    // detail (DSN, file paths, stack frames) to the caller. Log it fully
    // server-side, return only a generic message.
    error_log('[provisioner] unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['error' => 'Internal error.']);
}
