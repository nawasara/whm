<?php

namespace Nawasara\Whm\Models;

use Illuminate\Database\Eloquent\Model;
use Nawasara\Sync\Concerns\HasSyncStatus;

/**
 * Snapshot model for cPanel hosting accounts (managed via WHM).
 *
 * Read selalu dari DB, write via queue jobs.
 */
class WhmAccount extends Model
{
    use HasSyncStatus;

    protected $table = 'nawasara_whm_accounts';

    protected $fillable = [
        'instance', 'username', 'domain', 'email', 'plan', 'ip', 'owner',
        'disk_used_mb', 'disk_limit_mb', 'bandwidth_used_mb', 'bandwidth_limit_mb',
        'inodes_used', 'inodes_limit',
        'suspended', 'suspend_reason', 'start_date',
        'humanized',
        'sync_status', 'sync_error', 'last_synced_at',
        'content_hash',
    ];

    protected $casts = [
        'disk_used_mb' => 'decimal:2',
        'disk_limit_mb' => 'integer',
        'bandwidth_used_mb' => 'decimal:2',
        'bandwidth_limit_mb' => 'integer',
        'inodes_used' => 'integer',
        'inodes_limit' => 'integer',
        'suspended' => 'boolean',
        'start_date' => 'datetime',
        'humanized' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Compute hash dari fields yang relevan untuk conflict detection.
     */
    public function computeContentHash(): string
    {
        return hash('sha256', json_encode([
            'plan' => $this->plan,
            'disk_limit_mb' => $this->disk_limit_mb,
            'bandwidth_limit_mb' => $this->bandwidth_limit_mb,
            'suspended' => $this->suspended,
        ]));
    }

    /**
     * Disk usage as percentage of limit.
     */
    public function diskUsagePercent(): ?float
    {
        if (! $this->disk_limit_mb || $this->disk_limit_mb <= 0) {
            return null;
        }
        return round(($this->disk_used_mb / $this->disk_limit_mb) * 100, 1);
    }

    public function bandwidthUsagePercent(): ?float
    {
        if (! $this->bandwidth_limit_mb || $this->bandwidth_limit_mb <= 0) {
            return null;
        }
        return round(($this->bandwidth_used_mb / $this->bandwidth_limit_mb) * 100, 1);
    }

    public function maxUsagePercent(): float
    {
        return max(
            $this->diskUsagePercent() ?? 0,
            $this->bandwidthUsagePercent() ?? 0,
        );
    }

    public function usageState(): string
    {
        $max = $this->maxUsagePercent();
        $crit = (int) config('nawasara-whm.usage_critical_threshold', 95);
        $warn = (int) config('nawasara-whm.usage_warning_threshold', 80);

        if ($max >= $crit) return 'critical';
        if ($max >= $warn) return 'warning';
        return 'ok';
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
            $q->where('username', 'like', $term)
                ->orWhere('domain', 'like', $term)
                ->orWhere('email', 'like', $term);
        });
    }

    public function scopeStatus($query, ?string $status)
    {
        if (! $status) return $query;
        if ($status === 'suspended') return $query->where('suspended', true);
        if ($status === 'active') return $query->where('suspended', false);
        return $query;
    }

    public function scopePlan($query, ?string $plan)
    {
        return $plan ? $query->where('plan', $plan) : $query;
    }
}
