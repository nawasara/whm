<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `user_id` nullable di nawasara_webmail_sessions.
 *
 * Original schema dirancang untuk self-launch only (user_id = user yang
 * launch emailnya sendiri, selalu terisi). Setelah admin impersonation
 * flow ditambahkan, user_id bisa null di kondisi:
 *   - Admin akses email yang TIDAK terhubung ke user Nawasara manapun
 *     (mis. mailbox shared atau mailbox legacy yang belum di-link).
 *   - Resolver tidak bisa nemuin UserEmailLink untuk email tsb.
 *
 * NULL di sini bukan error — itu valid state "target email bukan akun
 * user di sistem Nawasara". Yang wajib NOT NULL untuk impersonation row
 * adalah `acted_by_user_id` (admin yang launch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_webmail_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverse: set NOT NULL kembali. Catatan: kalau ada baris dengan
        // user_id NULL, rollback akan fail. Itu intended — operator harus
        // backfill atau hapus impersonation rows dulu sebelum rollback.
        Schema::table('nawasara_webmail_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
