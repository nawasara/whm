<?php

namespace Nawasara\Whm\Models;

use Illuminate\Database\Eloquent\Model;
use Nawasara\Sync\Concerns\HasSyncStatus;

/**
 * Snapshot model for WHM email accounts.
 *
 * UI selalu read dari table ini (cepat). Write ops dispatch jobs yang
 * update DB + push ke WHM API. Scheduled sync periodic refresh dari WHM.
 */
class WhmEmailAccount extends Model
{
    use HasSyncStatus;

    protected $table = 'nawasara_whm_email_accounts';

    protected $fillable = [
        'instance', 'cpanel_user',
        'email', 'local_part', 'domain',
        'quota_mb', 'disk_used_mb', 'humanized',
        'suspended_login', 'suspended_incoming',
        'sync_status', 'sync_error', 'last_synced_at',
        'content_hash',
    ];

    protected $casts = [
        'quota_mb' => 'integer',
        'disk_used_mb' => 'decimal:3',
        'humanized' => 'array',
        'suspended_login' => 'boolean',
        'suspended_incoming' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Compute hash dari fields yang relevan untuk conflict detection.
     * Dipakai sebelum dispatch mutation jobs.
     */
    public function computeContentHash(): string
    {
        return hash('sha256', json_encode([
            'quota_mb' => $this->quota_mb,
            'suspended_login' => $this->suspended_login,
            'suspended_incoming' => $this->suspended_incoming,
        ]));
    }

    public function isSuspended(): bool
    {
        return $this->suspended_login || $this->suspended_incoming;
    }

    public function scopeForInstance($query, ?string $instance)
    {
        return $instance ? $query->where('instance', $instance) : $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) return $query;
        $term = '%'.$term.'%';
        return $query->where(function ($q) use ($term) {
            $q->where('email', 'like', $term)
                ->orWhere('local_part', 'like', $term)
                ->orWhere('domain', 'like', $term);
        });
    }

    /**
     * Polymorphic active/suspended filter. Email is "suspended" if either
     * suspended_login OR suspended_incoming is true. Selecting BOTH
     * 'active' and 'suspended' is a no-op (every row matches).
     *
     * @param  string|array<int,string>|null  $status
     */
    public function scopeStatus($query, string|array|null $status)
    {
        if (empty($status)) {
            return $query;
        }

        $values = is_array($status) ? $status : [$status];
        $wantActive = in_array('active', $values, true);
        $wantSuspended = in_array('suspended', $values, true);

        if ($wantActive && ! $wantSuspended) {
            return $query->where('suspended_login', false)
                ->where('suspended_incoming', false);
        }
        if ($wantSuspended && ! $wantActive) {
            return $query->where(function ($q) {
                $q->where('suspended_login', true)
                    ->orWhere('suspended_incoming', true);
            });
        }
        return $query;
    }
}
