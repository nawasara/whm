<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawasara_whm_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('instance', 64)->index();        // 'WHM-Hosting'
            $table->string('username', 64)->index();        // cPanel username
            $table->string('domain', 255)->nullable();      // primary domain
            $table->string('email', 255)->nullable();       // contact email
            $table->string('plan', 100)->nullable();        // package name
            $table->string('ip', 45)->nullable();
            $table->string('owner', 64)->nullable();        // reseller owner

            // Disk & bandwidth (parsed to MB)
            $table->decimal('disk_used_mb', 12, 2)->nullable();
            $table->unsignedInteger('disk_limit_mb')->nullable();   // null = unlimited
            $table->decimal('bandwidth_used_mb', 12, 2)->nullable();
            $table->unsignedInteger('bandwidth_limit_mb')->nullable();

            // Inodes
            $table->unsignedBigInteger('inodes_used')->nullable();
            $table->unsignedBigInteger('inodes_limit')->nullable();

            // Status
            $table->boolean('suspended')->default(false);
            $table->string('suspend_reason', 255)->nullable();
            $table->timestamp('start_date')->nullable();    // unix_startdate

            // Raw humanized values (for display fallback)
            $table->json('humanized')->nullable();

            // Sync tracking (HasSyncStatus trait columns)
            $table->string('sync_status', 32)->default('synced');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            // Hash for conflict detection
            $table->string('content_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['instance', 'username'], 'whm_acct_unique_per_instance');
            $table->index(['instance', 'sync_status']);
            $table->index(['instance', 'suspended']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_whm_accounts');
    }
};
