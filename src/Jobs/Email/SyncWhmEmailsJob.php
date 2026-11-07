<?php

namespace Nawasara\Whm\Jobs\Email;

use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Whm\Models\WhmEmailAccount;
use Nawasara\Whm\Services\WhmClient;

/**
 * Full sync semua email accounts di 1 instance dari WHM API → DB snapshot.
 *
 * Trigger:
 *   - Scheduled: setiap 1 jam
 *   - Manual: via "Sync Sekarang" button di UI
 *   - On-demand: setelah mutation job berhasil (refresh single record)
 *
 * Strategy:
 *   1. Fetch list_pops (light, tanpa disk)
 *   2. Optionally fetch list_pops_with_disk untuk update disk usage
 *   3. Upsert ke DB
 *   4. Mark email yang hilang dari WHM sebagai deleted (soft)
 */
class SyncWhmEmailsJob extends AbstractSyncJob
{
    public int $timeout = 600; // 10 menit untuk 1000+ accounts

    protected function service(): string
    {
        return 'whm';
    }

    protected function action(): string
    {
        return 'sync_emails';
    }

    protected function targetType(): ?string
    {
        return 'WhmEmailAccount';
    }

    protected function targetId(): ?string
    {
        return null; // bulk operation
    }

    protected function execute(): array
    {
        $whm = app(WhmClient::class)->forInstance($this->instance);

        if (! $whm->isConfigured()) {
            throw new \RuntimeException("WHM instance {$this->instance} is not configured");
        }

        $cpanelUser = $whm->defaultCpanelUser();
        if (! $cpanelUser) {
            throw new \RuntimeException("Cannot resolve cPanel user for instance {$this->instance}");
        }

        $withDisk = (bool) ($this->payload['with_disk'] ?? false);

        // Fetch dari WHM
        $remote = $whm->listEmailAccounts($cpanelUser, $withDisk);

        $stats = [
            'total' => count($remote),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
        ];

        $seenEmails = [];

        foreach ($remote as $row) {
            $email = $row['email'] ?? null;
            if (! $email) {
                continue;
            }

            // For "Main Account" entries (kalau muncul), skip — bukan email account real
            if ($email === $cpanelUser || ! str_contains($email, '@')) {
                continue;
            }

            $seenEmails[] = $email;

            [$localPart, $domain] = explode('@', $email, 2);

            $attrs = [
                'cpanel_user' => $cpanelUser,
                'email' => $email,
                'local_part' => $localPart,
                'domain' => $domain,
                'suspended_login' => (bool) ($row['suspended_login'] ?? false),
                'suspended_incoming' => (bool) ($row['suspended_incoming'] ?? false),
                'sync_status' => WhmEmailAccount::SYNC_SYNCED,
                'sync_error' => null,
                'last_synced_at' => now(),
            ] + self::diskAttrs($row);

            $existing = WhmEmailAccount::where('instance', $this->instance)
                ->where('email', $email)
                ->first();

            if ($existing) {
                // Compute hash baru — kalau sama, skip update untuk efisiensi
                $tempModel = new WhmEmailAccount(array_merge($existing->toArray(), $attrs));
                $newHash = $tempModel->computeContentHash();

                if ($existing->content_hash === $newHash && $existing->isSynced()) {
                    $stats['unchanged']++;
                    continue;
                }

                $existing->update(array_merge($attrs, ['content_hash' => $newHash]));
                $stats['updated']++;
            } else {
                $tempModel = new WhmEmailAccount(array_merge(['instance' => $this->instance], $attrs));
                $newHash = $tempModel->computeContentHash();

                WhmEmailAccount::create(array_merge(['instance' => $this->instance], $attrs, [
                    'content_hash' => $newHash,
                ]));
                $stats['created']++;
            }
        }

        // Email yang hilang dari WHM = di-delete dari sisi sana → tandai di DB
        if (! empty($seenEmails)) {
            $stats['deactivated'] = WhmEmailAccount::where('instance', $this->instance)
                ->whereNotIn('email', $seenEmails)
                ->where('sync_status', '!=', WhmEmailAccount::SYNC_PENDING_DELETE)
                ->where('sync_status', '!=', WhmEmailAccount::SYNC_PENDING_CREATE)
                ->delete();
        }

        // Flush WhmClient cache supaya next list dari API segar
        $whm->flushEmailCache($cpanelUser);

        return $stats;
    }

    /**
     * Quota and usage columns — ONLY when the WHM row carries them.
     *
     * The hourly light sync uses `list_pops`, which has no quota or disk
     * fields. Writing them anyway (as null) erased what the nightly
     * `list_pops_with_disk` run had stored, and every consumer then read
     * null as "unlimited" — the ASN account portal showed every mailbox as
     * "tak terbatas" (reference_sync_partial_source_overwrite).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function diskAttrs(array $row): array
    {
        $hasQuota = array_key_exists('_diskquota', $row) || array_key_exists('diskquota', $row);
        $hasUsed = array_key_exists('_diskused', $row) || array_key_exists('diskused', $row);
        if (! $hasQuota && ! $hasUsed) {
            return [];
        }

        $out = ['humanized' => [
            'diskquota' => $row['_diskquota'] ?? $row['diskquota'] ?? null,
            'diskused' => $row['_diskused'] ?? $row['diskused'] ?? null,
        ]];
        if ($hasQuota) {
            $out['quota_mb'] = static::parseQuota($row);
        }
        if ($hasUsed) {
            $out['disk_used_mb'] = static::parseDiskUsed($row);
        }

        return $out;
    }

    /**
     * Parse quota dari raw API response.
     * WHM kadang return: '0' (unlimited), '250' (MB), '250M', '1G', dll.
     */
    protected static function parseQuota(array $row): ?int
    {
        $raw = $row['_diskquota'] ?? $row['diskquota'] ?? null;
        if ($raw === null || $raw === '' || $raw === '0' || $raw === 0 || strtolower((string) $raw) === 'unlimited') {
            return null; // unlimited
        }
        return static::parseToMb($raw);
    }

    protected static function parseDiskUsed(array $row): ?float
    {
        $raw = $row['_diskused'] ?? $row['diskused'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $mb = static::parseToMb($raw);
        return $mb !== null ? (float) $mb : null;
    }

    protected static function parseToMb($input): ?int
    {
        if (is_numeric($input)) {
            $val = (float) $input;
            // WHM API kadang return bytes (bukan MB) — nilai > 100_000 hampir pasti bytes.
            // 100_000 MB = 100 GB, tidak mungkin quota email individual sebesar itu.
            return $val > 100_000 ? (int) round($val / 1_048_576) : (int) $val;
        }
        $s = strtoupper(trim((string) $input));
        if (! preg_match('/^([\d.]+)\s*(K|M|G|T)?B?$/', $s, $m)) {
            return null;
        }
        $num = (float) $m[1];
        $unit = $m[2] ?? 'M';
        return (int) match ($unit) {
            'K' => $num / 1024,
            'M' => $num,
            'G' => $num * 1024,
            'T' => $num * 1024 * 1024,
            default => $num,
        };
    }
}
