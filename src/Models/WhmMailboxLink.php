<?php

namespace Nawasara\Whm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cPanel External Authentication link: the Keycloak identity `subject`
 * (a NIP on the Kisara provider) may sign in to `mailbox`. A mailbox linked
 * to several subjects is shared by those people.
 *
 * Synced by SyncWhmMailboxLinksJob; links removed in cPanel disappear here
 * on the next sync, because the link is what grants access.
 */
class WhmMailboxLink extends Model
{
    protected $table = 'nawasara_whm_mailbox_links';

    protected $fillable = [
        'instance', 'mailbox', 'provider_id', 'subject', 'display_name', 'linked_at', 'synced_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
