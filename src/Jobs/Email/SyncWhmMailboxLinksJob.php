<?php

namespace Nawasara\Whm\Jobs\Email;

use Illuminate\Support\Carbon;
use Nawasara\Sync\Jobs\AbstractSyncJob;
use Nawasara\Whm\Models\WhmMailboxLink;
use Nawasara\Whm\Services\WhmClient;
use Nawasara\Whm\Support\AuthnLinks;

/**
 * Mirror cPanel External Authentication links (mailbox ↔ Keycloak subject)
 * of one mail instance into nawasara_whm_mailbox_links.
 *
 * The ASN account portal decides which mailboxes a civil servant may open
 * from these rows, so two rules matter:
 *
 *   - A link removed in cPanel is DELETED here (not soft-deleted): keeping
 *     it would keep granting access the mail team has revoked.
 *   - A failed or malformed WHM answer throws before anything is deleted,
 *     so a WHM hiccup never wipes everyone's access.
 */
class SyncWhmMailboxLinksJob extends AbstractSyncJob
{
    public int $timeout = 120;

    protected function service(): string
    {
        return 'whm';
    }

    protected function action(): string
    {
        return 'sync_mailbox_links';
    }

    protected function targetType(): ?string
    {
        return 'WhmMailboxLink';
    }

    protected function targetId(): ?string
    {
        return null;
    }

    protected function execute(): array
    {
        $whm = app(WhmClient::class)->forInstance($this->instance);

        if (! $whm->isConfigured()) {
            throw new \RuntimeException("WHM instance {$this->instance} is not configured");
        }

        $response = $whm->rawJsonApi('get_users_authn_linked_accounts');

        if ((int) ($response['metadata']['result'] ?? 0) !== 1) {
            throw new \RuntimeException('WHM get_users_authn_linked_accounts failed: '.($response['metadata']['reason'] ?? 'no reason'));
        }

        $links = AuthnLinks::fromResponse($response);

        // Zero links while we already hold some is far more likely a changed
        // response shape than the mail team unlinking everyone at once.
        if ($links === [] && WhmMailboxLink::where('instance', $this->instance)->exists()) {
            throw new \RuntimeException('WHM returned no mailbox links; refusing to remove the existing ones.');
        }

        $now = now();
        $created = 0;
        $updated = 0;
        $keep = [];

        foreach ($links as $link) {
            $row = WhmMailboxLink::firstOrNew([
                'instance' => $this->instance,
                'mailbox' => $link['mailbox'],
                'provider_id' => $link['provider_id'],
                'subject' => $link['subject'],
            ]);

            $row->exists ? $updated++ : $created++;

            $row->fill([
                'display_name' => $link['display_name'],
                'linked_at' => $link['linked_at'] ? Carbon::createFromTimestamp($link['linked_at']) : null,
                'synced_at' => $now,
            ])->save();

            $keep[] = $row->id;
        }

        $removed = WhmMailboxLink::query()
            ->where('instance', $this->instance)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();

        return ['created' => $created, 'updated' => $updated, 'removed' => $removed, 'total' => count($links)];
    }
}
