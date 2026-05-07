<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log per session launch (success + failure + rejection).
 *
 * Status semantics:
 *   - issued   : URL session berhasil di-forge dan ditampilkan ke user
 *   - failed   : WHM API error / mailbox tidak ditemukan / mailbox suspended
 *   - rejected : business rule fail (multi-mailbox tanpa manual override,
 *                user belum punya link, dll) — tidak sempat panggil WHM
 *
 * Lokasi: nawasara-whm. Migration ini awalnya di nawasara-core (commit
 * 24b247b di nawasara-core), dipindah ke nawasara-whm 2026-05-08 untuk
 * konsistensi dengan pattern nawasara-vault yang punya audit table di
 * package masing-masing. Lihat git history kalau butuh archaeology.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_webmail_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('email_account', 255)->nullable(); // null kalau rejected sebelum resolve
            $table->enum('match_strategy', ['sso_attribute', 'manual'])->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->enum('status', ['issued', 'failed', 'rejected'])->default('issued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['email_account', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_webmail_sessions');
    }
};
