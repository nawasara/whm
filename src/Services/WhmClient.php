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

    /**
     * Get role for a specific instance ('hosting', 'mail', 'both', or null).
     */
    public function roleOf(?string $instance): ?string
    {
        return Vault::get('whm', 'role', $instance);
    }

    /**
     * List instances filtered by role. 'both' instances always included.
     *
     * @param  string  $role  'hosting' or 'mail'
     * @return array  list of instance names
     */
    public function instancesByRole(string $role): array
    {
        return collect($this->instances())
            ->filter(function ($instance) use ($role) {
                $instanceRole = $this->roleOf($instance);
                // Default behavior for backward compat: instance tanpa role dianggap 'both'.
                if (! $instanceRole) {
                    return true;
                }
                return $instanceRole === $role || $instanceRole === 'both';
            })
            ->values()
            ->all();
    }

    /**
     * Pick a default instance for the given role.
     * Returns first matching instance or null.
     */
    public function defaultInstanceForRole(string $role): ?string
    {
        return $this->instancesByRole($role)[0] ?? null;
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
            ->connectTimeout(config('nawasara-whm.connect_timeout', 30))
            ->timeout(config('nawasara-whm.timeout', 60))
            // Force IPv4 — IPv6 routing kadang lambat/timeout di network Indonesia
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
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

    // ─── Email Accounts (cPanel UAPI Email module) ──────

    /**
     * Call cPanel UAPI for a specific cPanel user via WHM API endpoint.
     * Endpoint: /json-api/cpanel?cpanel_jsonapi_user=...&cpanel_jsonapi_module=...&cpanel_jsonapi_func=...
     *
     * Response shape (apiversion=3):
     * {
     *   "result": {
     *     "status": 1,
     *     "errors": [...] or null,
     *     "warnings": [...] or null,
     *     "messages": [...] or null,
     *     "data": [...] or {...}
     *   }
     * }
     */
    protected function uapi(string $cpanelUser, string $module, string $function, array $params = [], string $method = 'get'): array
    {
        $query = array_merge([
            'cpanel_jsonapi_user' => $cpanelUser,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
            'cpanel_jsonapi_apiversion' => 3,
        ], $params);

        // For write operations, send params in body (POST). Auth headers stay the same.
        $response = $method === 'get'
            ? $this->api()->get('cpanel', $query)
            : $this->api()->asForm()->post('cpanel', $query);

        if (! $response->successful()) {
            return ['success' => false, 'errors' => ['HTTP '.$response->status().': '.$response->body()], 'data' => []];
        }

        $body = $response->json();

        // WHM wraps cpanel response under 'cpanelresult' for older versions, 'result' for newer.
        // Strip wrappers until we hit the actual result block.
        $result = $body['cpanelresult']['result'] ?? $body['result'] ?? $body;

        // Normalize errors/warnings/messages — they can be null, scalar, or array
        $errors = $result['errors'] ?? [];
        if (! is_array($errors)) $errors = $errors ? [(string) $errors] : [];

        $warnings = $result['warnings'] ?? [];
        if (! is_array($warnings)) $warnings = $warnings ? [(string) $warnings] : [];

        $messages = $result['messages'] ?? [];
        if (! is_array($messages)) $messages = $messages ? [(string) $messages] : [];

        // Status: UAPI uses status=1 for success; WHM API uses metadata.result=1
        $status = $result['status'] ?? $body['metadata']['result'] ?? null;
        $success = $status === 1 || $status === '1' || $status === true;

        // Log API call for debugging
        if (config('app.debug') || config('nawasara-whm.debug', false)) {
            logger()->info('[WhmClient] UAPI call', [
                'instance' => $this->instance,
                'user' => $cpanelUser,
                'module' => $module,
                'function' => $function,
                'method' => $method,
                'success' => $success,
                'errors' => $errors,
                'http_status' => $response->status(),
                'raw_body' => $body,  // <-- log raw response untuk debug
            ]);
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'warnings' => $warnings,
            'messages' => $messages,
            'data' => $result['data'] ?? [],
            'raw' => $body,
        ];
    }

    /**
     * List email accounts under a specific cPanel user.
     * UAPI: Email::list_pops_with_disk (slower, ~10-60s for 1000+) or list_pops (fast)
     *
     * @param  bool  $withDisk  include disk usage (slower for large accounts)
     */
    public function listEmailAccounts(string $cpanelUser, bool $withDisk = false): array
    {
        $func = $withDisk ? 'list_pops_with_disk' : 'list_pops';
        $result = $this->uapi($cpanelUser, 'Email', $func);

        return $result['success'] ? ($result['data'] ?? []) : [];
    }

    /**
     * Get default cPanel user for the active instance — the parent of all email accounts.
     * For Kominfo: usually 'ponorogo' (cPanel user owning ponorogo.go.id).
     *
     * Strategy:
     *  1. Use first cPanel account from listAccounts() (works for single-cPanel servers like WHM-Ryder).
     *  2. Caller can override with explicit user.
     */
    public function defaultCpanelUser(): ?string
    {
        $accounts = $this->getCachedAccounts();
        return $accounts[0]['user'] ?? null;
    }

    public function getCachedEmailAccounts(?string $cpanelUser = null, bool $withDisk = false): array
    {
        $cpanelUser ??= $this->defaultCpanelUser();
        if (! $cpanelUser) return [];

        $suffix = $withDisk ? ':disk' : ':light';
        $key = 'whm_email_accounts:'.($this->instance ?? 'default').':'.$cpanelUser.$suffix;
        // Disk-included version cache lebih lama (mahal di-call), light version 5 menit standar
        $ttl = $withDisk ? 600 : config('nawasara-whm.cache_ttl', 300);

        return Cache::remember($key, $ttl, function () use ($cpanelUser, $withDisk) {
            return $this->listEmailAccounts($cpanelUser, $withDisk);
        });
    }

    /**
     * Create new email account.
     * UAPI: Email::add_pop
     */
    public function createEmailAccount(string $cpanelUser, string $email, string $password, int $quotaMb = 250): array
    {
        // Email format: localpart@domain → split untuk UAPI
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);

        if (! $localPart || ! $domain) {
            return ['success' => false, 'errors' => ['Format email tidak valid'], 'data' => []];
        }

        return $this->uapi($cpanelUser, 'Email', 'add_pop', [
            'email' => $localPart,
            'domain' => $domain,
            'password' => $password,
            'quota' => $quotaMb,
        ], 'post');
    }

    /**
     * Delete email account.
     * UAPI: Email::delete_pop
     */
    public function deleteEmailAccount(string $cpanelUser, string $email): bool
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);

        $result = $this->uapi($cpanelUser, 'Email', 'delete_pop', [
            'email' => $localPart,
            'domain' => $domain,
        ], 'post');

        return $result['success'];
    }

    /**
     * Change email account password.
     * UAPI: Email::passwd_pop
     *
     * @return array{success: bool, errors: array, message: string}
     */
    public function changeEmailPassword(string $cpanelUser, string $email, string $password): array
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);

        $result = $this->uapi($cpanelUser, 'Email', 'passwd_pop', [
            'email' => $localPart,
            'domain' => $domain,
            'password' => $password,
        ], 'post');

        return [
            'success' => $result['success'],
            'errors' => $result['errors'] ?? [],
            'message' => $result['errors'][0] ?? ($result['messages'][0] ?? ''),
        ];
    }

    /**
     * Edit email account quota in MB. Pass 0 for unlimited.
     * UAPI: Email::edit_pop_quota
     */
    public function changeEmailQuota(string $cpanelUser, string $email, int $quotaMb): bool
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);

        $result = $this->uapi($cpanelUser, 'Email', 'edit_pop_quota', [
            'email' => $localPart,
            'domain' => $domain,
            'quota' => $quotaMb,
        ], 'post');

        return $result['success'];
    }

    /**
     * Suspend incoming mail OR login for an email account.
     * UAPI: Email::suspend_incoming / Email::suspend_login
     *
     * @param  string  $type  'incoming' or 'login' or 'both'
     */
    public function suspendEmailAccount(string $cpanelUser, string $email, string $type = 'both'): bool
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);
        $params = ['email' => $localPart, 'domain' => $domain];

        $ok = true;
        if ($type === 'incoming' || $type === 'both') {
            $ok = $ok && ($this->uapi($cpanelUser, 'Email', 'suspend_incoming', $params, 'post')['success'] ?? false);
        }
        if ($type === 'login' || $type === 'both') {
            $ok = $ok && ($this->uapi($cpanelUser, 'Email', 'suspend_login', $params, 'post')['success'] ?? false);
        }

        return $ok;
    }

    public function unsuspendEmailAccount(string $cpanelUser, string $email, string $type = 'both'): bool
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, null);
        $params = ['email' => $localPart, 'domain' => $domain];

        $ok = true;
        if ($type === 'incoming' || $type === 'both') {
            $ok = $ok && ($this->uapi($cpanelUser, 'Email', 'unsuspend_incoming', $params, 'post')['success'] ?? false);
        }
        if ($type === 'login' || $type === 'both') {
            $ok = $ok && ($this->uapi($cpanelUser, 'Email', 'unsuspend_login', $params, 'post')['success'] ?? false);
        }

        return $ok;
    }

    /**
     * Flush email cache for current instance (call after mutations).
     */
    public function flushEmailCache(?string $cpanelUser = null): void
    {
        $cpanelUser ??= $this->defaultCpanelUser();
        if (! $cpanelUser) return;

        $base = 'whm_email_accounts:'.($this->instance ?? 'default').':'.$cpanelUser;
        Cache::forget($base.':light');
        Cache::forget($base.':disk');
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
