<?php

namespace Nawasara\Whm\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Nawasara\Vault\Facades\Vault;

/**
 * WHM API v1 client.
 *
 * Auth: Authorization: whm <username>:<API_TOKEN>
 * Base URL: https://<host>:2087/json-api
 *
 * Docs: https://api.docs.cpanel.net/whm/introduction/
 */
class WhmClient
{
    /** Active server instance name (null = default). */
    protected ?string $instance = null;

    /** Cached credentials per-instance. */
    protected array $credsCache = [];

    /**
     * Switch active server instance. Returns new cloned client.
     */
    public function forInstance(?string $instance): static
    {
        $clone = clone $this;
        $clone->instance = $instance ?: null;
        return $clone;
    }

    public function instance(): ?string
    {
        return $this->instance;
    }

    /**
     * List all configured WHM instances from Vault.
     */
    public function instances(): array
    {
        return Vault::instances('whm');
    }

    protected function credentials(): array
    {
        $key = $this->instance ?? '__default__';

        if (isset($this->credsCache[$key])) {
            return $this->credsCache[$key];
        }

        return $this->credsCache[$key] = [
            'host' => Vault::get('whm', 'host', $this->instance),
            'username' => Vault::get('whm', 'username', $this->instance),
            'api_token' => Vault::get('whm', 'api_token', $this->instance),
        ];
    }

    protected function api(): PendingRequest
    {
        $creds = $this->credentials();
        $host = rtrim($creds['host'] ?? '', '/');

        // WHM default port 2087 (SSL). User dapat kasih host:port custom.
        if (! str_contains($host, ':') && ! str_starts_with($host, 'http')) {
            $host = "https://{$host}:2087";
        } elseif (! str_starts_with($host, 'http')) {
            $host = "https://{$host}";
        }

        return Http::baseUrl($host.'/json-api')
            ->withHeaders([
                'Authorization' => 'whm '.($creds['username'] ?? '').':'.($creds['api_token'] ?? ''),
            ])
            ->acceptJson()
            ->timeout(config('nawasara-whm.timeout', 30))
            // Banyak WHM pakai self-signed cert atau cert yang dicheck di level proxy.
            ->withoutVerifying();
    }

    public function isConfigured(): bool
    {
        return Vault::has('whm', 'host', $this->instance)
            && Vault::has('whm', 'username', $this->instance)
            && Vault::has('whm', 'api_token', $this->instance);
    }

    /**
     * Test connection to WHM. Called from Vault UI.
     */
    public function testConnection(?string $instance = null): array
    {
        $client = $instance ? $this->forInstance($instance) : $this;

        if (! $client->isConfigured()) {
            return ['success' => false, 'message' => 'Credential belum lengkap'];
        }

        try {
            $response = $client->api()->get('version', ['api.version' => 1]);

            if ($response->successful() && $response->json('data.version')) {
                return [
                    'success' => true,
                    'message' => 'Terhubung — WHM '.$response->json('data.version'),
                ];
            }

            $reason = $response->json('metadata.reason') ?? 'HTTP '.$response->status();
            return ['success' => false, 'message' => 'Gagal: '.$reason];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Error: '.$e->getMessage()];
        }
    }

    // ─── Accounts ───────────────────────────────────────

    /**
     * List all accounts on the server.
     * WHM endpoint: listaccts
     */
    public function listAccounts(array $params = []): array
    {
        $response = $this->api()->get('listaccts', array_merge([
            'api.version' => 1,
        ], $params));

        if ($response->successful()) {
            return $response->json('data.acct', []) ?? [];
        }

        return [];
    }

    public function getCachedAccounts(): array
    {
        $key = 'whm_accounts:'.($this->instance ?? 'default');
        return Cache::remember($key, config('nawasara-whm.cache_ttl', 300), function () {
            return $this->listAccounts();
        });
    }

    /**
     * Summary for a single account (info lebih ringkas, tapi per-user).
     * WHM endpoint: accountsummary
     */
    public function getAccount(string $username): ?array
    {
        $response = $this->api()->get('accountsummary', [
            'api.version' => 1,
            'user' => $username,
        ]);

        if ($response->successful()) {
            $acct = $response->json('data.acct', []);
            return is_array($acct) && ! empty($acct) ? ($acct[0] ?? $acct) : null;
        }

        return null;
    }

    /**
     * Create new cPanel account.
     * WHM endpoint: createacct
     */
    public function createAccount(array $data): array
    {
        $response = $this->api()->post('createacct', array_merge([
            'api.version' => 1,
        ], $data));

        return [
            'success' => $response->successful() && (int) $response->json('metadata.result', 0) === 1,
            'message' => $response->json('metadata.reason', 'Unknown'),
            'data' => $response->json('data', []),
        ];
    }

    /**
     * Suspend account.
     * WHM endpoint: suspendacct
     */
    public function suspendAccount(string $username, ?string $reason = null): bool
    {
        $response = $this->api()->post('suspendacct', array_filter([
            'api.version' => 1,
            'user' => $username,
            'reason' => $reason,
        ]));

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    /**
     * Unsuspend account.
     * WHM endpoint: unsuspendacct
     */
    public function unsuspendAccount(string $username): bool
    {
        $response = $this->api()->post('unsuspendacct', [
            'api.version' => 1,
            'user' => $username,
        ]);

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    /**
     * Terminate account (hapus permanen!).
     * WHM endpoint: removeacct
     */
    public function terminateAccount(string $username, bool $keepDns = false): bool
    {
        $response = $this->api()->post('removeacct', [
            'api.version' => 1,
            'user' => $username,
            'keepdns' => $keepDns ? 1 : 0,
        ]);

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    /**
     * Change password for account.
     * WHM endpoint: passwd
     */
    public function changePassword(string $username, string $password): bool
    {
        $response = $this->api()->post('passwd', [
            'api.version' => 1,
            'user' => $username,
            'password' => $password,
        ]);

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    /**
     * Change package/plan for account.
     * WHM endpoint: changepackage
     */
    public function changePackage(string $username, string $package): bool
    {
        $response = $this->api()->post('changepackage', [
            'api.version' => 1,
            'user' => $username,
            'pkg' => $package,
        ]);

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    /**
     * Get disk usage for account.
     * WHM endpoint: get_disk_usage (API 1)
     */
    public function getDiskUsage(string $username): ?array
    {
        $response = $this->api()->get('get_disk_usage', [
            'api.version' => 1,
            'user' => $username,
        ]);

        if ($response->successful()) {
            return $response->json('data', []);
        }

        return null;
    }

    // ─── Packages ───────────────────────────────────────

    /**
     * List all hosting packages.
     * WHM endpoint: listpkgs
     */
    public function listPackages(): array
    {
        $response = $this->api()->get('listpkgs', [
            'api.version' => 1,
        ]);

        if ($response->successful()) {
            return $response->json('data.pkg', []) ?? [];
        }

        return [];
    }

    public function getCachedPackages(): array
    {
        $key = 'whm_packages:'.($this->instance ?? 'default');
        return Cache::remember($key, config('nawasara-whm.cache_ttl', 300), function () {
            return $this->listPackages();
        });
    }

    /**
     * Create new package.
     * WHM endpoint: addpkg
     */
    public function createPackage(array $data): array
    {
        $response = $this->api()->post('addpkg', array_merge([
            'api.version' => 1,
        ], $data));

        return [
            'success' => $response->successful() && (int) $response->json('metadata.result', 0) === 1,
            'message' => $response->json('metadata.reason', 'Unknown'),
        ];
    }

    /**
     * Delete package.
     * WHM endpoint: killpkg
     */
    public function deletePackage(string $name): bool
    {
        $response = $this->api()->post('killpkg', [
            'api.version' => 1,
            'pkg' => $name,
        ]);

        return $response->successful() && (int) $response->json('metadata.result', 0) === 1;
    }

    // ─── Server Status ──────────────────────────────────

    /**
     * Get server version info.
     * WHM endpoint: version
     */
    public function getVersion(): ?string
    {
        $response = $this->api()->get('version', ['api.version' => 1]);

        return $response->successful() ? $response->json('data.version') : null;
    }

    /**
     * Get server load average.
     * WHM endpoint: systemloadavg
     */
    public function getLoadAverage(): ?array
    {
        $response = $this->api()->get('systemloadavg', ['api.version' => 1]);

        return $response->successful() ? $response->json('data', []) : null;
    }

    /**
     * Service status (httpd, mysql, exim, etc.).
     * WHM endpoint: servicestatus
     */
    public function getServiceStatus(): array
    {
        $response = $this->api()->get('servicestatus', ['api.version' => 1]);

        if ($response->successful()) {
            return $response->json('data.service', []) ?? [];
        }

        return [];
    }

    /**
     * Get disk information from server (all partitions).
     * WHM endpoint: getdiskusage
     */
    public function getServerDiskUsage(): array
    {
        $response = $this->api()->get('getdiskusage', ['api.version' => 1]);

        if ($response->successful()) {
            return $response->json('data.partition', []) ?? [];
        }

        return [];
    }

    public function getCachedServerStatus(): array
    {
        $key = 'whm_server_status:'.($this->instance ?? 'default');
        return Cache::remember($key, 60, function () {
            return [
                'version' => $this->getVersion(),
                'load' => $this->getLoadAverage(),
                'services' => $this->getServiceStatus(),
                'disk' => $this->getServerDiskUsage(),
            ];
        });
    }

    // ─── Utility ────────────────────────────────────────

    /**
     * Flush all WHM caches for current instance (call after mutations).
     */
    public function flushCache(): void
    {
        $suffix = ':'.($this->instance ?? 'default');
        Cache::forget('whm_accounts'.$suffix);
        Cache::forget('whm_packages'.$suffix);
        Cache::forget('whm_server_status'.$suffix);
    }
}
