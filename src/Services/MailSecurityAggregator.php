<?php

namespace Nawasara\Whm\Services;

use Carbon\Carbon;

/**
 * Aggregate mail security stats from Exim reject log over SSH.
 *
 * On cPanel servers /var/log/exim_rejectlog captures every connection that
 * Exim rejected — auth failures, RBL hits, unknown recipients, content
 * filter blocks, etc. This is far more useful operationally than a
 * "spam-only" view because the bulk of attacks are brute-force auth
 * attempts on virtual mail users, not classic content spam.
 *
 * SpamAssassin (`spam=Yes` in mainlog) is also surfaced when present.
 */
class MailSecurityAggregator
{
    public const REJECTLOG = '/var/log/exim_rejectlog';
    public const MAINLOG = EximClient::DEFAULT_MAINLOG;

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

    public function isConfigured(): bool
    {
        return $this->ssh->isConfigured();
    }

    /**
     * Counts of rejected connections, broken down by category, for a date
     * range. Categories are heuristic substring matches — Exim does not
     * tag the line, but the message text is consistent enough.
     *
     * @return array{total:int, auth_fail:int, rbl:int, unknown_user:int, spam:int, other:int}
     */
    public function getRejectCounts(Carbon $from, Carbon $to): array
    {
        $path = escapeshellarg(self::REJECTLOG);
        $fromArg = escapeshellarg($from->format('Y-m-d'));
        $toArg = escapeshellarg($to->format('Y-m-d'));

        // Single awk pass over the date-filtered subset.
        $script = <<<SH
TMP=\$(mktemp) && (cat $path 2>/dev/null; zcat -f $path.*.gz 2>/dev/null) | awk '\$1 >= $fromArg && \$1 <= $toArg' > \$TMP 2>/dev/null
echo "total=\$(wc -l < \$TMP)"
echo "auth_fail=\$(grep -c -F 'authenticator failed' \$TMP)"
echo "rbl=\$(grep -ciE 'RBL|blocked using|listed in|spamhaus' \$TMP)"
echo "unknown_user=\$(grep -ciE 'unknown user|unrouteable address|no such user' \$TMP)"
echo "spam=\$(grep -ciE 'spam|score=[5-9]\\.|score=[1-9][0-9]+\\.' \$TMP)"
rm -f \$TMP
SH;

        $output = $this->ssh->withTimeout(60)->exec($script);

        $counts = $this->parseKeyValues($output, [
            'total' => 0, 'auth_fail' => 0, 'rbl' => 0,
            'unknown_user' => 0, 'spam' => 0,
        ]);

        // "other" = anything not categorized
        $categorized = $counts['auth_fail'] + $counts['rbl'] + $counts['unknown_user'] + $counts['spam'];
        $counts['other'] = max(0, $counts['total'] - $categorized);

        return $counts;
    }

    /**
     * Daily reject count trend for the chart.
     *
     * @return array<int, array{date:string, total:int, auth_fail:int, rbl:int, unknown_user:int, spam:int}>
     */
    public function getDailyTrend(int $days = 7): array
    {
        $days = max(1, min($days, 90));
        $from = now()->subDays($days - 1)->startOfDay()->format('Y-m-d');
        $fromArg = escapeshellarg($from);
        $path = self::REJECTLOG;

        $combine = sprintf(
            '(cat %s 2>/dev/null; zcat -f %s.*.gz 2>/dev/null)',
            escapeshellarg($path),
            escapeshellarg($path),
        );

        $script = <<<AWK
$combine | awk -v from=$fromArg '
\$1 >= from {
  d=\$1
  total[d]++
  if (\$0 ~ /authenticator failed/) auth[d]++
  else if (tolower(\$0) ~ /rbl|blocked using|listed in|spamhaus/) rbl[d]++
  else if (tolower(\$0) ~ /unknown user|unrouteable address|no such user/) unk[d]++
  else if (tolower(\$0) ~ /spam/) spam[d]++
}
END {
  for (d in total) print d"|total|"total[d]
  for (d in auth) print d"|auth_fail|"auth[d]
  for (d in rbl) print d"|rbl|"rbl[d]
  for (d in unk) print d"|unknown_user|"unk[d]
  for (d in spam) print d"|spam|"spam[d]
}' 2>/dev/null
AWK;

        $output = $this->ssh->withTimeout(90)->exec($script);

        $byDate = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            $parts = explode('|', $line);
            if (count($parts) !== 3) {
                continue;
            }
            [$date, $kind, $count] = $parts;
            $byDate[$date] ??= ['date' => $date, 'total' => 0, 'auth_fail' => 0, 'rbl' => 0, 'unknown_user' => 0, 'spam' => 0];
            $byDate[$date][$kind] = (int) $count;
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $result[] = $byDate[$d] ?? ['date' => $d, 'total' => 0, 'auth_fail' => 0, 'rbl' => 0, 'unknown_user' => 0, 'spam' => 0];
        }

        return $result;
    }

    /**
     * Top source IPs from today's rejected connections.
     *
     * @return array<int, array{ip:string, count:int}>
     */
    public function getTopBlockedIps(int $limit = 15): array
    {
        $today = now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return [];
        }
        $limit = max(1, min($limit, 100));
        $path = escapeshellarg(self::REJECTLOG);

        // Reject log lines vary in format; the IP we want is the first
        // bracketed numeric address: [a.b.c.d]
        $cmd = sprintf(
            "grep \"^%s\" %s 2>/dev/null | grep -oE '\\[([0-9]{1,3}\\.){3}[0-9]{1,3}\\]' | sort | uniq -c | sort -rn | head -n %d",
            $today,
            $path,
            $limit,
        );

        $output = $this->ssh->withTimeout(45)->exec($cmd);

        $result = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (preg_match('/^\s*(\d+)\s+\[([0-9.]+)\]\s*$/', $line, $m)) {
                $result[] = ['ip' => $m[2], 'count' => (int) $m[1]];
            }
        }
        return $result;
    }

    /**
     * Top targeted user IDs (set_id=...) — usually accounts under
     * brute-force attack.
     *
     * @return array<int, array{username:string, count:int}>
     */
    public function getTopTargetedAccounts(int $limit = 15): array
    {
        $today = now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return [];
        }
        $limit = max(1, min($limit, 100));
        $path = escapeshellarg(self::REJECTLOG);

        // Pattern: set_id=username@domain or set_id=username
        $cmd = sprintf(
            "grep \"^%s\" %s 2>/dev/null | grep -oE 'set_id=[^)]+' | sed 's/^set_id=//' | sort | uniq -c | sort -rn | head -n %d",
            $today,
            $path,
            $limit,
        );

        $output = $this->ssh->withTimeout(45)->exec($cmd);

        $result = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+?)\s*$/', $line, $m)) {
                $result[] = ['username' => $m[2], 'count' => (int) $m[1]];
            }
        }
        return $result;
    }

    /**
     * Most recent reject log entries, newest first.
     *
     * @return array<int, array{timestamp:string, raw:string, summary:string, ip:?string, user:?string, category:string}>
     */
    public function getRecentRejects(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $path = escapeshellarg(self::REJECTLOG);

        $cmd = sprintf('tail -n %d %s 2>/dev/null', $limit, $path);
        $output = $this->ssh->withTimeout(30)->exec($cmd);

        $entries = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if ($line === '') {
                continue;
            }

            $ts = '';
            $rest = $line;
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+(.*)$/', $line, $m)) {
                $ts = $m[1];
                $rest = $m[2];
            }

            $ip = null;
            if (preg_match('/\[([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\]/', $rest, $m)) {
                $ip = $m[1];
            }

            $user = null;
            if (preg_match('/set_id=([^)]+)/', $rest, $m)) {
                $user = trim($m[1]);
            }

            $category = $this->categorize($rest);

            $entries[] = [
                'timestamp' => $ts,
                'raw' => $line,
                'summary' => mb_strimwidth($rest, 0, 140, '…'),
                'ip' => $ip,
                'user' => $user,
                'category' => $category,
            ];
        }

        return array_reverse($entries);
    }

    protected function categorize(string $line): string
    {
        $l = strtolower($line);
        return match (true) {
            str_contains($l, 'authenticator failed') => 'auth_fail',
            preg_match('/rbl|blocked using|listed in|spamhaus/i', $line) === 1 => 'rbl',
            preg_match('/unknown user|unrouteable address|no such user/i', $line) === 1 => 'unknown_user',
            str_contains($l, 'spam') => 'spam',
            default => 'other',
        };
    }

    protected function parseKeyValues(string $output, array $defaults): array
    {
        $result = $defaults;
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (preg_match('/^([a-z_]+)=(\d+)$/', trim($line), $m)) {
                $result[$m[1]] = (int) $m[2];
            }
        }
        return $result;
    }
}
