<?php

namespace Nawasara\Whm\Services;

use Nawasara\Vault\Facades\Vault;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * SSH connection wrapper for WHM instances.
 *
 * Pattern: lazy connect on first command, pooled per-request via the service
 * container so multiple commands within the same Livewire request reuse one
 * underlying SSH2 connection. Each instance has its own pool entry.
 *
 * Credentials live in Vault group `whm`:
 *   - ssh_host (optional, falls back to parsed WHM host)
 *   - ssh_port (default 22)
 *   - ssh_user (default root)
 *   - ssh_key  (PEM private key)
 *
 * Usage:
 *   app(SshConnection::class)->forInstance('WHM-Ryder')->exec('exim -bpc');
 */
class SshConnection
{
    /** Active server instance name (null = default). */
    protected ?string $instance = null;

    /** Per-request connection pool, keyed by instance name. */
    protected array $pool = [];

    /** Default command timeout in seconds. */
    protected int $timeout = 30;

    public function forInstance(?string $instance): static
    {
        $clone = clone $this;
        $clone->instance = $instance ?: null;
        // Pool is shared via reference so clones see the same connections.
        $clone->pool = &$this->pool;
        return $clone;
    }

    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeout = max(1, $seconds);
        return $clone;
    }

    public function instance(): ?string
    {
        return $this->instance;
    }

    /**
     * Whether SSH credentials are configured for the active instance.
     */
    public function isConfigured(): bool
    {
        $creds = $this->credentials();
        return ! empty($creds['ssh_user']) && ! empty($creds['ssh_key']) && ! empty($creds['host']);
    }

    /**
     * Run a command and return stdout. Throws RuntimeException on connection
     * or auth failure; non-zero exit codes return stdout (caller decides what
     * to do — many shell tools return non-zero on "no results" which is fine).
     */
    public function exec(string $command): string
    {
        $ssh = $this->connect();
        $ssh->setTimeout($this->timeout);

        $output = $ssh->exec($command);

        if ($output === false) {
            throw new RuntimeException('SSH command failed: '.($ssh->getLastError() ?: 'unknown error'));
        }

        return is_string($output) ? $output : '';
    }

    /**
     * Run command and return both stdout and exit status.
     *
     * @return array{stdout: string, exit_status: int}
     */
    public function execWithStatus(string $command): array
    {
        $stdout = $this->exec($command);
        $ssh = $this->connect();

        return [
            'stdout' => $stdout,
            'exit_status' => $ssh->getExitStatus() ?: 0,
        ];
    }

    /**
     * Test the connection — returns true if authenticated, false otherwise.
     * Does not throw.
     */
    public function testConnection(): bool
    {
        try {
            $ssh = $this->connect();
            return $ssh->isAuthenticated();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lazy-connect (or return pooled) SSH2 client for the active instance.
     */
    protected function connect(): SSH2
    {
        $key = $this->instance ?? '__default__';

        if (isset($this->pool[$key]) && $this->pool[$key]->isConnected()) {
            return $this->pool[$key];
        }

        $creds = $this->credentials();

        if (empty($creds['ssh_user']) || empty($creds['ssh_key']) || empty($creds['host'])) {
            throw new RuntimeException('SSH credentials not configured for instance: '.($this->instance ?? 'default'));
        }

        $ssh = new SSH2($creds['host'], (int) $creds['ssh_port'], $this->timeout);

        try {
            $privateKey = PublicKeyLoader::load($creds['ssh_key']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid SSH private key for instance '.($this->instance ?? 'default').': '.$e->getMessage(), 0, $e);
        }

        if (! $ssh->login($creds['ssh_user'], $privateKey)) {
            throw new RuntimeException('SSH authentication failed for '.$creds['ssh_user'].'@'.$creds['host'].':'.$creds['ssh_port']);
        }

        return $this->pool[$key] = $ssh;
    }

    /**
     * Resolve effective SSH credentials from Vault. SSH host falls back to the
     * WHM API host (stripped of scheme/port) when ssh_host is empty.
     *
     * @return array{host: ?string, ssh_port: int, ssh_user: ?string, ssh_key: ?string}
     */
    protected function credentials(): array
    {
        $sshHost = Vault::get('whm', 'ssh_host', $this->instance) ?: null;
        $whmHost = Vault::get('whm', 'host', $this->instance);

        // Fallback: parse host from WHM URL when ssh_host not set
        if (! $sshHost && $whmHost) {
            $parsed = parse_url($whmHost);
            $sshHost = $parsed['host'] ?? null;
        }

        $port = Vault::get('whm', 'ssh_port', $this->instance) ?: 22;
        $user = Vault::get('whm', 'ssh_user', $this->instance) ?: 'root';
        $key = Vault::get('whm', 'ssh_key', $this->instance);

        return [
            'host' => $sshHost,
            'ssh_port' => (int) $port,
            'ssh_user' => $user,
            'ssh_key' => $key,
        ];
    }

    public function __destruct()
    {
        foreach ($this->pool as $ssh) {
            if ($ssh instanceof SSH2 && $ssh->isConnected()) {
                $ssh->disconnect();
            }
        }
    }
}
