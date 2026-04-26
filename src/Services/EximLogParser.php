<?php

namespace Nawasara\Whm\Services;

use Carbon\Carbon;

/**
 * Parse Exim CLI output (queue listing, mainlog lines).
 *
 * Pure functions, no I/O — fed by EximClient over SSH.
 */
class EximLogParser
{
    /**
     * Parse `exim -bp` output into an array of queue items.
     *
     * Each block in the output looks like:
     *
     *   12h  1.2K 1qABCd-0001Yz-3F <sender@example.com>
     *           recipient@example.org
     *
     * Frozen messages have an extra "*** frozen ***" line. Deferred ones
     * carry the last error after the recipient. Multiple recipients become
     * separate lines under the same id.
     *
     * @return array<int, array{
     *   id: string, age: string, age_seconds: int, size: string,
     *   sender: ?string, recipients: array<int,string>,
     *   status: string, last_error: ?string,
     * }>
     */
    public function parseQueue(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        $items = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $output) as $line) {
            // Header line for a queue entry: starts with the age column.
            // Format: <age> <size> <id> <<sender>>
            // Message ID shape: XXXXXX-XXXXXX-XX (last segment >= 2 chars,
            // older Exim) or XXXXXX-XXXXXXXXXXX-XXXX (newer cPanel/Exim).
            if (preg_match('/^\s*(\S+)\s+(\S+)\s+([A-Za-z0-9]{6}-[A-Za-z0-9]{6,}-[A-Za-z0-9]{2,})\s+<([^>]*)>\s*$/', $line, $m)) {
                if ($current) {
                    $items[] = $current;
                }
                $current = [
                    'id' => $m[3],
                    'age' => $m[1],
                    'age_seconds' => $this->parseAge($m[1]),
                    'size' => $m[2],
                    'sender' => $m[4] ?: null,
                    'recipients' => [],
                    'status' => 'queued',
                    'last_error' => null,
                ];
                continue;
            }

            if (! $current) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // Frozen marker.
            if (str_contains($trimmed, '*** frozen ***')) {
                $current['status'] = 'frozen';
                continue;
            }

            // Recipient line — typically indented, plain email or "D <email>".
            if (preg_match('/^(?:D\s+)?([^\s@]+@[^\s@]+\.[^\s@]+)$/', $trimmed, $m)) {
                $current['recipients'][] = $m[1];
                continue;
            }

            // Anything else after the header is treated as last error / status text.
            if (! $current['last_error']) {
                $current['last_error'] = $trimmed;
                if ($current['status'] === 'queued') {
                    $current['status'] = 'deferred';
                }
            }
        }

        if ($current) {
            $items[] = $current;
        }

        return $items;
    }

    /**
     * Parse an Exim age string (e.g. "12h", "3d", "45m", "30s", "2h45m") into seconds.
     */
    public function parseAge(string $age): int
    {
        $age = trim($age);
        if ($age === '') {
            return 0;
        }

        $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;

        if (preg_match_all('/(\d+)\s*([dhms])/i', $age, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $total += ((int) $m[1]) * ($units[strtolower($m[2])] ?? 0);
            }
            return $total;
        }

        // Plain number = seconds.
        if (is_numeric($age)) {
            return (int) $age;
        }

        return 0;
    }

    /**
     * Parse a single Exim mainlog line.
     *
     * Mainlog format:
     *   2026-04-26 12:34:56 1qABCd-0001Yz-3F <= sender@example.com H=...
     *   2026-04-26 12:35:01 1qABCd-0001Yz-3F => recipient@example.org R=...
     *   2026-04-26 12:35:01 1qABCd-0001Yz-3F Completed
     *   2026-04-26 12:36:11 1qABCd-0001Yz-3F == recipient@example.org R=lookuphost defer ...
     *   2026-04-26 12:37:22 1qABCd-0001Yz-3F ** recipient@example.org R=... bounce ...
     *
     * Returns null for unparseable lines (e.g. cwd= log echoes).
     *
     * @return ?array{
     *   timestamp: ?Carbon, raw_timestamp: string,
     *   message_id: ?string, direction: string,
     *   address: ?string, status: string, info: string,
     * }
     */
    public function parseLogLine(string $line): ?array
    {
        $line = rtrim($line);
        if ($line === '') {
            return null;
        }

        // Standard format: TIMESTAMP MSGID ARROW ADDRESS REST
        // ARROW: <= (incoming), => (delivered), -> (additional recipient),
        //        ** (bounce), == (deferred), Completed (no arrow)
        if (! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(\S+)\s+(.*)$/', $line, $m)) {
            return null;
        }

        $rawTs = $m[1];
        $rest = $m[3];
        $messageId = null;
        $candidateId = $m[2];

        // Exim message IDs: XXXXXX-XXXXXX-XX (16 char) or wider IDs on
        // newer cPanel/Exim builds (XXXXXX-XXXXXXXXXXX-XXXX ~24 char).
        if (preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{6,}-[A-Za-z0-9]{2,}$/', $candidateId)) {
            $messageId = $candidateId;
        } else {
            // No msgid — treat $candidateId as part of message body
            $rest = $candidateId.' '.$rest;
        }

        $direction = 'other';
        $status = 'info';
        $address = null;
        $info = $rest;

        if (preg_match('/^(<=|=>|->|\*\*|==)\s+(\S+)(.*)$/', $rest, $m2)) {
            $arrow = $m2[1];
            $address = $m2[2];
            $info = trim($m2[3]);
            $direction = match ($arrow) {
                '<=' => 'in',
                '=>', '->' => 'out',
                '**' => 'bounce',
                '==' => 'defer',
                default => 'other',
            };
            $status = match ($arrow) {
                '<=' => 'received',
                '=>', '->' => 'delivered',
                '**' => 'bounced',
                '==' => 'deferred',
                default => 'info',
            };
        } elseif (str_starts_with($rest, 'Completed')) {
            $direction = 'out';
            $status = 'completed';
        }

        return [
            'timestamp' => $this->safeCarbon($rawTs),
            'raw_timestamp' => $rawTs,
            'message_id' => $messageId,
            'direction' => $direction,
            'address' => $address,
            'status' => $status,
            'info' => $info,
        ];
    }

    protected function safeCarbon(string $raw): ?Carbon
    {
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
