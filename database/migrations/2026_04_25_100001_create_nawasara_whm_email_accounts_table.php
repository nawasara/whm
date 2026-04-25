<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_whm_email_accounts', function (Blueprint $table) {
            $table->id();

            // Source identification
            $table->string('instance', 64)->index();        // 'WHM-Ryder'
            $table->string('cpanel_user', 64)->index();     // 'ponorogo'

            // Email identity
            $table->string('email', 255);                   // 'pringgo@ponorogo.go.id'
            $table->string('local_part', 64);               // 'pringgo'
            $table->string('domain', 255)->index();         // 'ponorogo.go.id'

            // Quota & usage
            $table->unsignedInteger('quota_mb')->nullable();      // null = unlimited (WHM "0" = unlimited)
            $table->decimal('disk_used_mb', 12, 3)->nullable();
            $table->json('humanized')->nullable();                // raw _diskquota / _diskused strings dari API

            // Status
            $table->boolean('suspended_login')->default(false);
            $table->boolean('suspended_incoming')->default(false);

            // Sync tracking (HasSyncStatus trait columns)
            $table->string('sync_status', 32)->default('synced');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Hash for conflict detection — computed dari (quota, suspended flags) saat last sync
            $table->string('content_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['instance', 'email'], 'whm_email_unique_per_instance');
            $table->index(['instance', 'sync_status']);
            $table->index(['instance', 'cpanel_user']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_whm_email_accounts');
    }
};
