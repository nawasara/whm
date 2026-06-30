<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHM API returns _diskquota/_diskused as raw bytes (not MB) on some endpoints.
 * The original unsignedInteger(quota_mb) and decimal(12,3)(disk_used_mb) both
 * overflow for accounts with multi-GB quotas (e.g. 6291456000 bytes = 6 GB).
 *
 * Fix: widen both columns so even if a stray large value slips through the
 * parser, it doesn't crash the sync job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nawasara_whm_email_accounts', function (Blueprint $table) {
            // unsignedInteger max ~4.2 billion — fits bytes up to 4 GB only.
            // unsignedBigInteger max ~18.4 exabytes — safe for any realistic value.
            $table->unsignedBigInteger('quota_mb')->nullable()->change();

            // decimal(12,3) max ~999 million — not enough for large byte values.
            // decimal(16,3) max ~9.99 trillion — more than sufficient.
            $table->decimal('disk_used_mb', 16, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nawasara_whm_email_accounts', function (Blueprint $table) {
            $table->unsignedInteger('quota_mb')->nullable()->change();
            $table->decimal('disk_used_mb', 12, 3)->nullable()->change();
        });
    }
};
