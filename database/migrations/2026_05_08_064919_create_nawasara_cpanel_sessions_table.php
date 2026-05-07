<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log untuk cPanel session launch — admin impersonation flow only.
 *
 * Berbeda dengan nawasara_webmail_sessions:
 *   - Webmail dipakai DUA flow (self-launch dari portal ASN + admin impersonation)
 *   - cPanel HANYA flow admin impersonation. End-user tidak punya alasan
 *     business untuk akses cPanel mereka sendiri lewat Nawasara — kalau perlu
 *     mereka bisa direct login ke cPanel. Nawasara cuma broker untuk admin
 *     yang mau quick-access cPanel user lain tanpa "Login as User" di WHM.
 *
 * Karena cuma 1 flow, schema lebih sederhana — tidak ada launch_kind /
 * match_strategy. Semua row implicit impersonation. `acted_by_user_id`
 * NOT NULL untuk lock the contract di DB-level.
 *
 * Kalau di kemudian hari muncul use-case end-user self-launch ke cPanel,
 * tinggal nullable acted_by_user_id + tambah launch_kind. Tapi YAGNI
 * untuk sekarang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_cpanel_sessions', function (Blueprint $table) {
            $table->id();

            // Admin yang launch — selalu terisi karena cPanel launch hanya
            // mode impersonation. NOT NULL beda dari webmail_sessions yang
            // nullable untuk self-launch path.
            $table->unsignedBigInteger('acted_by_user_id');

            // Target cPanel account — username + instance + domain. Kita
            // simpan trio supaya audit page bisa render full context tanpa
            // join ke nawasara_whm_accounts (yang bisa stale kalau akun
            // sudah di-terminate setelah audit row di-create).
            $table->string('cpanel_user', 64);
            $table->string('domain', 255)->nullable();
            $table->string('instance', 64)->nullable()
                ->comment('WHM instance name dari Vault group "whm" (mis. "ryder", "default").');

            // Required justification — minimal 10 char, validated di Livewire.
            // Text karena bisa panjang (ticket ID, alasan troubleshoot, dll).
            $table->text('reason');

            // Standard request metadata
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Outcome — issued kalau WHM API balik URL OK, failed kalau API
            // gagal / akun suspended / tidak exist. Tidak ada `rejected`
            // status (beda dari webmail) karena tidak ada business rule
            // pre-launch yang bisa reject — admin permission cek + reason
            // validation = sudah cukup.
            $table->enum('status', ['issued', 'failed'])->default('issued');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['acted_by_user_id', 'created_at']);
            $table->index(['cpanel_user', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_cpanel_sessions');
    }
};
