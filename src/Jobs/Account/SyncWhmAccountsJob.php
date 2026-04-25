<?php

namespace Nawasara\Whm\Jobs\Account;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Whm\Models\WhmAccount;
use Nawasara\Whm\Services\WhmClient;

/**
 * Full sync semua cPanel hosting accounts dari WHM API → DB snapshot.
 *
 * Trigger:
 *   - Scheduled: setiap 30 menit
 *   - Manual: via "Sync Sekarang" button
 *   - On-demand: setelah create/suspend/terminate berhasil
 */
class SyncWhmAccountsJob extends AbstractSyncJob
{
    public int $timeout = 300;

    protected function service(): string
    {
        return 'whm';
    }

    protected function action(): string
    {
        return 'sync_accounts';
    }

    protected function targetType(): ?string
    {
        return 'WhmAccount';
    }

    protected function targetId(): ?string
    {
        return null;
    }

    protected function execute(): array
    {
        $whm = app(WhmClient::class)->forInstance($this->instance);

        if (! $whm->isConfigured()) {
            throw new \RuntimeException("WHM instance {$this->instance} is not configured");
        }

        $remote = $whm->listAccounts();

        $stats = [
            'total' => count($remote),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
        ];

        $seenUsernames = [];

        foreach ($remote as $row) {
            $username = $row['user'] ?? null;
            if (! $username) {
                continue;
            }

            $seenUsernames[] = $username;

            $attrs = [
                'username' => $username,
                'domain' => $row['domain'] ?? null,
                'email' => $row['email'] ?? null,
                'plan' => $row['plan'] ?? null,
                'ip' => $row['ip'] ?? null,
                'owner' => $row['owner'] ?? null,
                'disk_used_mb' => $this->parseToMb($row['diskused'] ?? null),
                'disk_limit_mb' => $this->parseQuotaToMb($row['disklimit'] ?? null),
                'bandwidth_used_mb' => $this->parseToMb($row['totalbytes'] ?? null) / 1024 / 1024 ?: null,
                'bandwidth_limit_mb' => $this->parseQuotaToMb($row['bwlimit'] ?? null),
                'inodes_used' => isset($row['inodesused']) ? (int) $row['inodesused'] : null,
                'inodes_limit' => isset($row['inodeslimit']) && $row['inodeslimit'] !== 'unlimited'
                    ? (int) $row['inodeslimit']
                    : null,
                'suspended' => ($row['suspended'] ?? 0) == 1,
                'suspend_reason' => $row['suspendreason'] ?? null,
                'start_date' => isset($row['unix_startdate']) ? \Carbon\Carbon::createFromTimestamp((int) $row['unix_startdate']) : null,
                'humanized' => [
                    'diskused' => $row['diskused'] ?? null,
                    'disklimit' => $row['disklimit'] ?? null,
                    'totalbytes' => $row['totalbytes'] ?? null,
                    'bwlimit' => $row['bwlimit'] ?? null,
                ],
                'sync_status' => WhmAccount::SYNC_SYNCED,
                'sync_error' => null,
                'last_synced_at' => now(),
            ];

            $existing = WhmAccount::where('instance', $this->instance)
                ->where('username', $username)
                ->first();

            if ($existing) {
                $tempModel = new WhmAccount(array_merge($existing->toArray(), $attrs));
                $newHash = $tempModel->computeContentHash();

                if ($existing->content_hash === $newHash && $existing->isSynced()) {
                    $stats['unchanged']++;
                    continue;
                }

                $existing->update(array_merge($attrs, ['content_hash' => $newHash]));
                $stats['updated']++;
            } else {
                $tempModel = new WhmAccount(array_merge(['instance' => $this->instance], $attrs));
                $newHash = $tempModel->computeContentHash();

                WhmAccount::create(array_merge(['instance' => $this->instance], $attrs, [
                    'content_hash' => $newHash,
                ]));
                $stats['created']++;
            }
        }

        // Account hilang dari WHM = di-terminate dari sisi sana
        if (! empty($seenUsernames)) {
            $stats['deactivated'] = WhmAccount::where('instance', $this->instance)
                ->whereNotIn('username', $seenUsernames)
                ->where('sync_status', '!=', WhmAccount::SYNC_PENDING_DELETE)
                ->where('sync_status', '!=', WhmAccount::SYNC_PENDING_CREATE)
                ->delete();
        }

        $whm->flushCache();

        return $stats;
    }

    protected function parseToMb($input): ?float
    {
        if ($input === null || $input === '') return null;
        if (is_numeric($input)) return (float) $input;

        $s = strtoupper(trim((string) $input));
        if (! preg_match('/^([\d.]+)\s*(K|M|G|T)?B?$/', $s, $m)) {
            return null;
        }
        $num = (float) $m[1];
        $unit = $m[2] ?? 'M';
        return match ($unit) {
            'K' => $num / 1024,
            'M' => $num,
            'G' => $num * 1024,
            'T' => $num * 1024 * 1024,
            default => $num,
        };
    }

    protected function parseQuotaToMb($input): ?int
    {
        if ($input === null || $input === '' || $input === 'unlimited' || $input === 0 || $input === '0') {
            return null;
        }
        $mb = $this->parseToMb($input);
        return $mb !== null ? (int) $mb : null;
    }
}
