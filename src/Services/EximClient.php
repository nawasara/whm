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

    public function __construct(
        protected SshConnection $ssh,
        protected EximLogParser $parser = new EximLogParser(),
    ) {
    }

    public function forInstance(?string $instance): static
    {
        $clone = clone $this;
        $clone->instance = $instance ?: null;
        $clone->ssh = $this->ssh->forInstance($instance);
        return $clone;
    }

    public function parser(): EximLogParser
    {
        return $this->parser;
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

    /**
     * Full Exim queue listing, parsed into structured items.
     *
     * @return array<int, array<string, mixed>>  see EximLogParser::parseQueue
     */
    public function getQueue(): array
    {
        $output = $this->ssh->exec('exim -bp 2>/dev/null');
        return $this->parser->parseQueue($output);
    }

    /**
     * Remove a single message from the queue.
     */
    public function deleteFromQueue(string $messageId): bool
    {
        $this->assertValidMessageId($messageId);
        $result = $this->ssh->execWithStatus('exim -Mrm '.escapeshellarg($messageId).' 2>&1');
        return $result['exit_status'] === 0;
    }

    /**
     * Bulk remove. Returns number of messages successfully removed.
     */
    public function deleteManyFromQueue(array $messageIds): int
    {
        if (empty($messageIds)) {
            return 0;
        }

        $valid = array_filter($messageIds, fn ($id) => $this->isValidMessageId($id));
        if (empty($valid)) {
            return 0;
        }

        $args = implode(' ', array_map('escapeshellarg', $valid));
        $result = $this->ssh->execWithStatus('exim -Mrm '.$args.' 2>&1');
        return $result['exit_status'] === 0 ? count($valid) : 0;
    }

    public function freeze(string $messageId): bool
    {
        $this->assertValidMessageId($messageId);
        $result = $this->ssh->execWithStatus('exim -Mf '.escapeshellarg($messageId).' 2>&1');
        return $result['exit_status'] === 0;
    }

    public function thaw(string $messageId): bool
    {
        $this->assertValidMessageId($messageId);
        $result = $this->ssh->execWithStatus('exim -Mt '.escapeshellarg($messageId).' 2>&1');
        return $result['exit_status'] === 0;
    }

    /**
     * Force delivery attempt now (non-blocking on the server).
     */
    public function forceDelivery(string $messageId): bool
    {
        $this->assertValidMessageId($messageId);
        $result = $this->ssh->execWithStatus('exim -M '.escapeshellarg($messageId).' 2>&1');
        return $result['exit_status'] === 0;
    }

    /**
     * Flush the entire queue (fire delivery attempts for all queued messages).
     */
    public function flushQueue(): bool
    {
        $result = $this->ssh->execWithStatus('exim -qff 2>&1');
        return $result['exit_status'] === 0;
    }

    /**
     * Give up retrying and bounce the message back to its sender.
     * Polite alternative to deleteFromQueue when delivery is permanently
     * failing — the sender gets a "delivery failed" notice.
     */
    public function bounce(string $messageId): bool
    {
        $this->assertValidMessageId($messageId);
        $result = $this->ssh->execWithStatus('exim -Mg '.escapeshellarg($messageId).' 2>&1');
        return $result['exit_status'] === 0;
    }

    /**
     * Per-message delivery log (exim -Mvl). Shows every attempt with
     * timestamp, MX hit, and SMTP response — gold for diagnosing why a
     * message is stuck (mailbox full, user unknown, RBL block, etc.).
     */
    public function messageDeliveryLog(string $messageId): string
    {
        $this->assertValidMessageId($messageId);
        return $this->ssh->exec('exim -Mvl '.escapeshellarg($messageId).' 2>/dev/null');
    }

    /**
     * Full message headers (from -Mvh).
     */
    public function messageHeaders(string $messageId): string
    {
        $this->assertValidMessageId($messageId);
        return $this->ssh->exec('exim -Mvh '.escapeshellarg($messageId).' 2>/dev/null');
    }

    /**
     * Message body, truncated to N lines.
     */
    public function messageBody(string $messageId, int $maxLines = 100): string
    {
        $this->assertValidMessageId($messageId);
        $maxLines = max(10, min($maxLines, 1000));
        return $this->ssh->exec('exim -Mvb '.escapeshellarg($messageId).' 2>/dev/null | head -n '.$maxLines);
    }

    /**
     * Defensive — Exim message IDs match a fixed shape. Reject anything else
     * to keep injected commands out of the SSH pipe even though we already
     * shell-escape arguments.
     */
    protected function isValidMessageId(string $id): bool
    {
        // Exim 4 message IDs: XXXXXX-XXXXXX-XX (older, 16 chars total) or
        // XXXXXX-XXXXXXXXXXX-XXXX (newer wide ids on cPanel ~24 chars).
        return (bool) preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{6,}-[A-Za-z0-9]{2,}$/', $id);
    }

    protected function assertValidMessageId(string $id): void
    {
        if (! $this->isValidMessageId($id)) {
            throw new \InvalidArgumentException("Invalid Exim message ID: {$id}");
        }
    }
}
