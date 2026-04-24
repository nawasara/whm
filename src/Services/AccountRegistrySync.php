<?php

namespace Nawasara\Whm\Services;

use Nawasara\Registry\Models\Asset;

class AccountRegistrySync
{
    public function __construct(protected WhmClient $whm)
    {
    }

    /**
     * Sync all WHM accounts (across all configured instances) as hosting_account assets.
     *
     * Flow:
     *  1. If asset is already linked via (package_ref=whm, external_id=username),
     *     refresh identifier and status.
     *  2. Else, if there's an existing unlinked asset with matching identifier
     *     (domain), attach package_ref + external_id.
     *  3. Else, create new unassigned asset for manual OPD/PIC assignment.
     */
    public function sync(): array
    {
        $stats = [
            'total' => 0,
            'created' => 0,
            'linked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
        ];

        $instances = $this->whm->instances();

        // Fallback: no instances configured but default credential exists.
        if (empty($instances) && $this->whm->isConfigured()) {
            $instances = [null];
        }

        $seenUsernames = [];

        foreach ($instances as $instance) {
            $client = $instance ? $this->whm->forInstance($instance) : $this->whm;

            if (! $client->isConfigured()) {
                continue;
            }

            $accounts = $client->listAccounts();
            $stats['total'] += count($accounts);

            foreach ($accounts as $acct) {
                $username = $acct['user'] ?? null;
                $domain = $acct['domain'] ?? null;
                if (! $username || ! $domain) {
                    continue;
                }

                $seenUsernames[] = $username;
                $suspended = ($acct['suspended'] ?? 0) == 1;
                $mappedStatus = $suspended ? 'inactive' : 'active';

                $asset = Asset::where('package_ref', 'whm')
                    ->where('external_id', $username)
                    ->first();

                if ($asset) {
                    $changes = [];
                    if ($asset->identifier !== $domain) {
                        $changes['identifier'] = $domain;
                    }
                    if ($asset->status !== $mappedStatus) {
                        $changes['status'] = $mappedStatus;
                    }
                    if ($changes) {
                        $asset->update($changes);
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    continue;
                }

                // Try to link existing unlinked asset by identifier.
                $existing = Asset::where('type', 'hosting_account')
                    ->where('identifier', $domain)
                    ->whereNull('external_id')
                    ->first();

                if ($existing) {
                    $existing->update([
                        'package_ref' => 'whm',
                        'external_id' => $username,
                    ]);
                    $stats['linked']++;
                    continue;
                }

                Asset::create([
                    'opd_id' => null,
                    'pic_id' => null,
                    'type' => 'hosting_account',
                    'identifier' => $domain,
                    'package_ref' => 'whm',
                    'external_id' => $username,
                    'status' => $mappedStatus,
                    'registered_at' => now(),
                    'discovered_at' => now(),
                    'notes' => 'WHM account: '.$username.($instance ? ' on '.$instance : ''),
                ]);
                $stats['created']++;
            }
        }

        // Mark deleted accounts inactive.
        if (! empty($seenUsernames)) {
            $stats['deactivated'] = Asset::where('package_ref', 'whm')
                ->where('type', 'hosting_account')
                ->where('status', 'active')
                ->whereNotNull('external_id')
                ->whereNotIn('external_id', $seenUsernames)
                ->update(['status' => 'inactive']);
        }

        return $stats;
    }
}
