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
     * Search the Exim mainlog with filters, returning parsed log entries.
     *
     * Strategy: build a `grep` pipeline on the server so we never transfer
     * the full log file (it can be GB-sized). Date filter is anchored to
     * the line prefix (`YYYY-MM-DD`). Other filters are passed as additional
     * `grep -i` stages.
     *
     * @param array{
     *   date_from?: string,   // YYYY-MM-DD
     *   date_to?: string,     // YYYY-MM-DD
     *   sender?: string,
     *   recipient?: string,
     *   message_id?: string,
     *   status?: string,      // 'received'|'delivered'|'bounced'|'deferred'
     *   limit?: int,          // default 500, max 5000
     *   path?: string,        // override mainlog path
     * } $filters
     * @return array<int, array<string, mixed>>  parsed log entries (newest first)
     */
    public function searchLog(array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 500);
        $limit = max(1, min($limit, 5000));

        $path = escapeshellarg($filters['path'] ?? self::DEFAULT_MAINLOG);

        // Build pipeline. Start with date-range awk if both dates given,
        // else cat the file. Then apply progressive grep filters.
        $pipeline = [];

        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if ($dateFrom && $dateTo && $this->isValidDate($dateFrom) && $this->isValidDate($dateTo)) {
            // awk range filter — efficient single-pass
            $pipeline[] = sprintf(
                "awk '$1 >= %s && $1 <= %s' %s",
                escapeshellarg($dateFrom),
                escapeshellarg($dateTo),
                $path,
            );
        } elseif ($dateFrom && $this->isValidDate($dateFrom)) {
            $pipeline[] = sprintf("awk '$1 >= %s' %s", escapeshellarg($dateFrom), $path);
        } elseif ($dateTo && $this->isValidDate($dateTo)) {
            $pipeline[] = sprintf("awk '$1 <= %s' %s", escapeshellarg($dateTo), $path);
        } else {
            $pipeline[] = sprintf('cat %s', $path);
        }

        foreach (['sender', 'recipient', 'message_id'] as $key) {
            $value = trim($filters[$key] ?? '');
            if ($value === '') {
                continue;
            }
            // Reject anything with shell metacharacters — only grep-friendly text allowed
            if (preg_match('/[\\\\$`\n\r;|&<>]/', $value)) {
                throw new \InvalidArgumentException("Invalid characters in {$key} filter");
            }
            $pipeline[] = 'grep -F -i '.escapeshellarg($value);
        }

        $status = $filters['status'] ?? '';
        $statusGrep = $this->statusToGrepPattern($status);
        if ($statusGrep !== null) {
            $pipeline[] = 'grep -F '.escapeshellarg($statusGrep);
        }

        // Take last N matches (newest first after reverse).
        $pipeline[] = "tail -n {$limit}";

        $cmd = implode(' | ', $pipeline).' 2>/dev/null';

        $output = $this->ssh->withTimeout(60)->exec($cmd);

        $lines = preg_split('/\r?\n/', trim($output));
        if ($lines === false) {
            return [];
        }

        // Newest first
        $lines = array_reverse(array_filter($lines, fn ($l) => $l !== ''));

        $entries = [];
        foreach ($lines as $line) {
            $parsed = $this->parser->parseLogLine($line);
            if ($parsed) {
                $entries[] = $parsed + ['raw' => $line];
            }
        }

        return $entries;
    }

    /**
     * Trace all log events for a single message id (receive → routing →
     * deliver/bounce/defer). Useful for "what happened to this email?".
     *
     * @return array<int, array<string, mixed>>  parsed events (oldest first)
     */
    public function traceMessage(string $messageId): array
    {
        $this->assertValidMessageId($messageId);

        $path = escapeshellarg(self::DEFAULT_MAINLOG);
        $cmd = sprintf('grep -F %s %s 2>/dev/null', escapeshellarg($messageId), $path);

        $output = $this->ssh->withTimeout(60)->exec($cmd);

        $entries = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            $parsed = $this->parser->parseLogLine($line);
            if ($parsed) {
                $entries[] = $parsed + ['raw' => $line];
            }
        }

        return $entries;
    }

    protected function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    /**
     * Map a UI status filter to the substring grep pattern that uniquely
     * identifies that event in an Exim log line.
     */
    protected function statusToGrepPattern(string $status): ?string
    {
        return match ($status) {
            'received' => ' <= ',
            'delivered' => ' => ',
            'bounced' => ' ** ',
            'deferred' => ' == ',
            default => null,
        };
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
