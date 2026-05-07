<?php

namespace Nawasara\Whm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log untuk webmail session launch — issued / failed / rejected.
 *
 * Dua flow yang nge-log ke sini:
 *   - Self-launch    : user buka emailnya sendiri lewat /webmail/launch
 *                      (entry point di nawasara-core).
 *                      `user_id` = user yang launch, `acted_by_user_id` null,
 *                      `launch_kind` = 'self'.
 *   - Impersonation  : admin buka email user lain lewat dropdown
 *                      'Buka Webmail' di Email accounts table.
 *                      `user_id` = target user (resolved dari email), atau null
 *                      kalau email tidak terkait user Nawasara manapun.
 *                      `acted_by_user_id` = admin yang launch.
 *                      `launch_kind` = 'impersonation', `reason` wajib terisi.
 *
 * Lokasi di nawasara-whm karena WHM adalah pemilik infra mailbox + API
 * `create_user_session` yang sebenarnya forge URL. nawasara-core depend ke
 * sini cuma sebagai consumer (entry-point user-facing portal ASN). Pattern
 * konsisten dengan nawasara-vault yang juga punya audit table di
 * package-nya sendiri (nawasara_vault_access_log).
 *
 * Catatan: ini bukan sync_jobs tracker (yang scope-nya state mutation
 * cross-service). Webmail launch read-only dari user perspective + tidak
 * touch DB Nawasara, jadi taruh di tabel terpisah lebih clean.
 */
class WebmailSession extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    public const KIND_SELF = 'self';
    public const KIND_IMPERSONATION = 'impersonation';

    protected $table = 'nawasara_webmail_sessions';

    protected $fillable = [
        'user_id',
        'acted_by_user_id',
        'email_account',
        'match_strategy',
        'launch_kind',
        'reason',
        'ip',
        'user_agent',
        'status',
        'error',
    ];

    public function user(): BelongsTo
    {
        $userModel = config('auth.providers.users.model');
        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Admin yang launch session — hanya terisi kalau launch_kind = impersonation.
     * Untuk self-launch akan null (admin = user itu sendiri di kolom user).
     */
    public function actor(): BelongsTo
    {
        $userModel = config('auth.providers.users.model');
        return $this->belongsTo($userModel, 'acted_by_user_id');
    }

    public function isImpersonation(): bool
    {
        return $this->launch_kind === self::KIND_IMPERSONATION;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter session yang di-launch oleh admin tertentu (untuk audit page
     * "session apa saja yang admin X buka").
     */
    public function scopeActedBy(Builder $query, int $adminUserId): Builder
    {
        return $query->where('acted_by_user_id', $adminUserId);
    }

    public function scopeImpersonations(Builder $query): Builder
    {
        return $query->where('launch_kind', self::KIND_IMPERSONATION);
    }

    public function scopeSelfLaunches(Builder $query): Builder
    {
        return $query->where('launch_kind', self::KIND_SELF);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_REJECTED]);
    }
}
