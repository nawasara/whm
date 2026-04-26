<?php

namespace Nawasara\Whm\Services;

use Carbon\Carbon;

/**
 * Aggregate email statistics from Exim mainlog over SSH.
 *
 * Strategy: every method runs a single grep+wc or grep+awk pipeline on the
 * server so we only transfer counters / small projections, never the raw
 * log file. Results are cached briefly per-instance to keep the dashboard
 * snappy under wire:poll.
 *
 * Pattern matchers (Exim arrow tokens, anchored as substrings):
 *   ' <= '  received (incoming)
 *   ' => '  delivered
 *   ' ** '  bounced
 *   ' == '  deferred
 *   spam=Yes  spam-tagged by SpamAssassin
 */
class EmailStatsAggregator
{
    public const DEFAULT_MAINLOG = EximClient::DEFAULT_MAINLOG;

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
     * Counts for a date range. Date filter is anchored to the line prefix
     * so awk does a single pass without a where-clause inside grep.
     *
     * @return array{received:int, delivered:int, bounced:int, deferred:int, spam:int}
     */
    public function getCounts(Carbon $from, Carbon $to): array
    {
        $path = escapeshellarg(self::DEFAULT_MAINLOG);
        $fromStr = escapeshellarg($from->format('Y-m-d'));
        $toStr = escapeshellarg($to->format('Y-m-d'));

        // Single awk pass that keeps lines in range, then we do per-token
        // grep+wc in parallel via shell substitution. Faster than four
        // separate full-file scans.
        $script = <<<SH
TMP=\$(mktemp) && awk '\$1 >= $fromStr && \$1 <= $toStr' $path > \$TMP 2>/dev/null
echo "received=\$(grep -F ' <= ' \$TMP | wc -l)"
echo "delivered=\$(grep -F ' => ' \$TMP | wc -l)"
echo "bounced=\$(grep -F ' ** ' \$TMP | wc -l)"
echo "deferred=\$(grep -F ' == ' \$TMP | wc -l)"
echo "spam=\$(grep -F 'spam=Yes' \$TMP | wc -l)"
rm -f \$TMP
SH;

        $output = $this->ssh->withTimeout(60)->exec($script);

        return $this->parseKeyValues($output, [
            'received' => 0, 'delivered' => 0, 'bounced' => 0,
            'deferred' => 0, 'spam' => 0,
        ]);
    }

    public function getQueueSize(): int
    {
        $output = trim($this->ssh->exec('exim -bpc 2>/dev/null'));
        return is_numeric($output) ? (int) $output : 0;
    }

    /**
     * Per-day counts for the trend chart. Returns N entries oldest-first.
     *
     * cPanel rotates exim_mainlog daily, so reading only the live file
     * misses yesterday and earlier. We zcat any rotated files alongside
     * the current one — `cat current; zcat *.gz` is a single pipeline
     * to awk so we avoid loading anything into PHP memory.
     *
     * @return array<int, array{date: string, received:int, delivered:int, bounced:int, deferred:int}>
     */
    public function getDailyTrend(int $days = 7): array
    {
        $days = max(1, min($days, 90));
        $from = now()->subDays($days - 1)->startOfDay()->format('Y-m-d');
        $fromArg = escapeshellarg($from);
        $path = self::DEFAULT_MAINLOG;

        // Combine live log with rotated archives. zcat -f tolerates plain
        // files too, so missing .gz on dev boxes won't break the pipe.
        $combine = sprintf(
            '(cat %s 2>/dev/null; zcat -f %s.*.gz 2>/dev/null)',
            escapeshellarg($path),
            escapeshellarg($path),
        );

        $script = <<<AWK
$combine | awk -v from=$fromArg '
\$1 >= from {
  d=\$1
  if (\$0 ~ / <= /) r[d]++
  else if (\$0 ~ / => /) s[d]++
  else if (\$0 ~ / \\*\\* /) b[d]++
  else if (\$0 ~ / == /) f[d]++
}
END {
  for (d in r) print d"|received|"r[d]
  for (d in s) print d"|delivered|"s[d]
  for (d in b) print d"|bounced|"b[d]
  for (d in f) print d"|deferred|"f[d]
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
            $byDate[$date] ??= ['date' => $date, 'received' => 0, 'delivered' => 0, 'bounced' => 0, 'deferred' => 0];
            $byDate[$date][$kind] = (int) $count;
        }

        // Fill missing days with zeros so the chart doesn't have gaps.
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->format('Y-m-d');
            $result[] = $byDate[$d] ?? [
                'date' => $d, 'received' => 0, 'delivered' => 0, 'bounced' => 0, 'deferred' => 0,
            ];
        }

        return $result;
    }

    /**
     * Today's per-hour incoming volume (received messages).
     *
     * @return array<int, array{hour:int, count:int}>  24 entries, hour 0-23
     */
    public function getHourlyVolumeToday(): array
    {
        $today = now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return $this->emptyHourly();
        }
        $path = escapeshellarg(self::DEFAULT_MAINLOG);

        // grep today's lines, filter to received events, extract hour.
        $cmd = sprintf(
            'grep "^%s" %s 2>/dev/null | grep -F " <= " | awk \'{print substr($2,1,2)}\' | sort | uniq -c',
            $today,
            $path,
        );

        $output = $this->ssh->withTimeout(45)->exec($cmd);

        $byHour = array_fill(0, 24, 0);
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            // Format: "  count  HH"
            if (preg_match('/^\s*(\d+)\s+(\d{2})\s*$/', $line, $m)) {
                $byHour[(int) $m[2]] = (int) $m[1];
            }
        }

        return $this->emptyHourly($byHour);
    }

    /**
     * Top N senders for today (counted from received events).
     *
     * @return array<int, array{sender:string, count:int}>
     */
    public function getTopSenders(int $limit = 10): array
    {
        $today = now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return [];
        }
        $limit = max(1, min($limit, 100));
        $path = escapeshellarg(self::DEFAULT_MAINLOG);

        // Received event: YYYY-MM-DD HH:MM:SS <msgid> <= sender@example.com ...
        $cmd = sprintf(
            'grep "^%s" %s 2>/dev/null | grep -F " <= " | awk \'{print $5}\' | grep -v "^<>$" | sort | uniq -c | sort -rn | head -n %d',
            $today,
            $path,
            $limit,
        );

        $output = $this->ssh->withTimeout(45)->exec($cmd);

        $result = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+?)\s*$/', $line, $m)) {
                $result[] = ['sender' => $m[2], 'count' => (int) $m[1]];
            }
        }
        return $result;
    }

    /**
     * Top N recipient domains for today (delivered events).
     *
     * @return array<int, array{domain:string, count:int}>
     */
    public function getTopRecipientDomains(int $limit = 10): array
    {
        $today = now()->format('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            return [];
        }
        $limit = max(1, min($limit, 100));
        $path = escapeshellarg(self::DEFAULT_MAINLOG);

        // Delivered events have two address forms in the log:
        //   ... => alice@dest.com R=... T=...                 (direct delivery)
        //   ... => mailbox <real@addr.com> R=virtual_user ... (cPanel virtual user)
        // For the direct form, col $5 is the email; for the virtual form, the
        // canonical address is in <...>. We use sed to extract the first
        // <email@domain> per line if present, otherwise fall back to col $5.
        $cmd = sprintf(
            "grep \"^%s\" %s 2>/dev/null | grep -F ' => ' | sed -nE 's/.* => [^ ]* <([^>]+@[^>]+)>.*/\\1/p; t; s/.* => ([^ ]+@[^ ]+).*/\\1/p' | awk -F'@' 'NF>1 {print \$2}' | sort | uniq -c | sort -rn | head -n %d",
            $today,
            $path,
            $limit,
        );

        $output = $this->ssh->withTimeout(45)->exec($cmd);

        $result = [];
        foreach (preg_split('/\r?\n/', trim($output)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)\s*$/', $line, $m)) {
                $result[] = ['domain' => $m[2], 'count' => (int) $m[1]];
            }
        }
        return $result;
    }

    protected function emptyHourly(?array $byHour = null): array
    {
        $byHour ??= array_fill(0, 24, 0);
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'count' => $byHour[$h] ?? 0];
        }
        return $result;
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
