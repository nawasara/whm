<?php

namespace Nawasara\Whm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log untuk admin cPanel session launch (impersonation only).
 *
 * Setiap row = satu attempt admin masuk ke cPanel akun lain via tombol
 * "Buka cPanel" di Account accounts table. Tidak ada self-launch path —
 * end-user tidak pernah generate row di tabel ini.
 *
 * Kontrak schema:
 *   - `acted_by_user_id` SELALU terisi (admin yang trigger)
 *   - `cpanel_user` = target cPanel username (bukan email!)
 *   - `reason` wajib min 10 char (di-validate di Livewire)
 *   - `status` = `issued` | `failed` (no `rejected` — tidak ada business
 *     rule pre-launch yang bisa reject)
 *
 * Lihat juga `WebmailSession` di package yang sama — pattern serupa tapi
 * untuk webmail, dengan dua flow (self + impersonation) sehingga schemanya
 * sedikit lebih kompleks (acted_by_user_id nullable, ada launch_kind).
 */
class CpanelSession extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';

    protected $table = 'nawasara_cpanel_sessions';

    protected $fillable = [
        'acted_by_user_id',
        'cpanel_user',
        'domain',
        'instance',
        'reason',
        'ip',
        'user_agent',
        'status',
        'error',
    ];

    /**
     * Admin yang launch session. Beda dari WebmailSession (yang punya
     * `user` + `actor` relation untuk dua flow), CpanelSession cuma punya
     * `actor` karena tidak ada concept "target user Nawasara" — target-nya
     * cPanel account, yang bisa atau tidak terkait ke user Nawasara
     * (relation itu di tabel registry/asset, bukan di sini).
     */
    public function actor(): BelongsTo
    {
        $userModel = config('auth.providers.users.model');
        return $this->belongsTo($userModel, 'acted_by_user_id');
    }

    public function scopeActedBy(Builder $query, int $adminUserId): Builder
    {
        return $query->where('acted_by_user_id', $adminUserId);
    }

    /**
     * Filter session ke cPanel account tertentu — untuk audit page
     * "siapa saja yang akses cPanel `dinkesxxxx`".
     */
    public function scopeForAccount(Builder $query, string $cpanelUser): Builder
    {
        return $query->where('cpanel_user', $cpanelUser);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
