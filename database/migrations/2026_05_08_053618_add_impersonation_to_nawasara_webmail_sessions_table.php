<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend webmail audit log untuk mendukung admin impersonation flow.
 *
 * Kolom baru:
 *   - acted_by_user_id : kalau set, baris ini adalah admin impersonation.
 *                        null = self-launch user (kompatibel dengan baris lama).
 *   - reason           : free-text "alasan akses" yang admin isi sebelum
 *                        launch. Wajib untuk launch_as supaya audit trail
 *                        actionable (atasan bisa lihat why).
 *   - launch_kind      : enum cepat untuk filtering (`self` | `impersonation`).
 *                        Bisa di-derive dari acted_by_user_id, tapi materialized
 *                        column lebih cepat untuk dashboard query "berapa
 *                        impersonation hari ini".
 *
 * Backward-compatible: row existing tetap valid (launch_kind di-default `self`,
 * acted_by_user_id null, reason null).
 *
 * Lokasi: nawasara-whm. Original commit di nawasara-core (e6db067), dipindah
 * ke nawasara-whm 2026-05-08 saat refactor relokasi WebmailSession model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_webmail_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('acted_by_user_id')
                ->nullable()
                ->after('user_id')
                ->comment('Admin yang launch session atas nama user lain (impersonation). Null=self-launch.');

            $table->string('launch_kind', 16)
                ->default('self')
                ->after('match_strategy')
                ->comment('self | impersonation — derived from acted_by_user_id, materialized for fast filter.');

            $table->text('reason')
                ->nullable()
                ->after('launch_kind')
                ->comment('Alasan akses yang admin isi sebelum launch_as. Wajib untuk impersonation, null untuk self.');

            $table->index('acted_by_user_id');
            $table->index(['launch_kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_webmail_sessions', function (Blueprint $table) {
            $table->dropIndex(['acted_by_user_id']);
            $table->dropIndex(['launch_kind', 'created_at']);
            $table->dropColumn(['acted_by_user_id', 'launch_kind', 'reason']);
        });
    }
};
