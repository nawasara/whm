<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // cPanel External Authentication links on the mail server: which
        // Keycloak identity (subject = NIP) may sign in to which mailbox.
        // Synced from WHM `get_users_authn_linked_accounts`; internal, so bigint.
        Schema::create('nawasara_whm_mailbox_links', function (Blueprint $table) {
            $table->id();
            $table->string('instance', 64);
            $table->string('mailbox', 191);          // full address, lower-case
            $table->string('provider_id', 64);       // e.g. kisara
            $table->string('subject', 191);          // subject_unique_identifier (NIP)
            $table->string('display_name')->nullable(); // preferred_username, "NIP - Nama"
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['instance', 'mailbox', 'provider_id', 'subject'], 'whm_mailbox_links_unique');
            $table->index('subject');
            $table->index('mailbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nawasara_whm_mailbox_links');
    }
};
