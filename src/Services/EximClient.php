<?php

namespace Nawasara\Whm\Services;

/**
 * Exim mail server client — operates over SSH against a WHM mail instance.
 *
 * All methods require SSH credentials in Vault group `whm` for the active
 * instance (see SshConnection for field details).
 *
 * Most methods that interact with Exim assume the SSH user has permission to
 * run /usr/sbin/exim (root or sudoers entry). On cPanel servers `root` is
 * typical; for tighter setups grant sudo NOPASSWD for the exim binary.
 *
 * This is the Day 1 skeleton — only smoke methods. Queue/log/spam methods
 * arrive in subsequent days.
 */
class EximClient
{
    /** Default path for Exim mainlog on cPanel servers. */
    public const DEFAULT_MAINLOG = '/var/log/exim_mainlog';

    protected ?string $instance = null;

    public function __construct(protected SshConnection $ssh)
    {
    }

    public function forInstance(?string $instance): static
    {
        $clone = clone $this;
        $clone->instance = $instance ?: null;
        $clone->ssh = $this->ssh->forInstance($instance);
        return $clone;
    }

    public function instance(): ?string
    {
        return $this->instance;
    }

    /**
     * Whether SSH is configured for the active instance.
     */
    public function isConfigured(): bool
    {
        return $this->ssh->isConfigured();
    }

    /**
     * Verify SSH connectivity + that exim binary is reachable.
     * Returns null on success, error message on failure (no exceptions).
     */
    public function testConnection(): ?string
    {
        if (! $this->isConfigured()) {
            return 'SSH credentials belum di-set di Vault.';
        }

        try {
            if (! $this->ssh->testConnection()) {
                return 'SSH authentication gagal — periksa user/key.';
            }

            // Smoke check: exim version (does not require root for -bV).
            $output = trim($this->ssh->exec('exim -bV 2>&1 | head -1'));

            if ($output === '') {
                return 'SSH connect ok, tapi exim tidak ditemukan di PATH.';
            }

            if (! str_contains(strtolower($output), 'exim')) {
                return 'SSH connect ok, tapi output exim -bV tidak dikenali: '.substr($output, 0, 100);
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Number of messages in the Exim queue.
     */
    public function getQueueCount(): int
    {
        $output = trim($this->ssh->exec('exim -bpc 2>/dev/null'));
        return is_numeric($output) ? (int) $output : 0;
    }

    /**
     * Tail the Exim mainlog. Returns raw log lines (caller may parse).
     *
     * @param  int  $lines  number of lines from the end
     * @param  string  $path optional override (defaults to /var/log/exim_mainlog)
     */
    public function tailLog(int $lines = 100, string $path = self::DEFAULT_MAINLOG): string
    {
        $lines = max(1, min($lines, 5000));
        $path = escapeshellarg($path);

        return $this->ssh->exec("tail -n {$lines} {$path} 2>/dev/null");
    }

    /**
     * Exim version string (useful for diagnostics).
     */
    public function version(): ?string
    {
        try {
            // Some servers log every command (cwd= log lines), so head -1 may
            // pick the log echo instead of the actual version. Filter for the
            // line that mentions "version" (case-insensitive).
            $output = $this->ssh->exec('exim -bV 2>&1');
            foreach (preg_split('/\r?\n/', $output) as $line) {
                if (stripos($line, 'version') !== false) {
                    return trim($line);
                }
            }
            return trim(strtok($output, "\n")) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
