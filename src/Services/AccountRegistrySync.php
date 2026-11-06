<?php

namespace Nawasara\Whm\Services;

use Nawasara\Whm\Models\WhmAccount;

/**
 * Mirrors every cPanel account into nawasara/registry as a `hosting_account`
 * asset, so it gets PICs (primary + backups) like any other asset — and the
 * ASN account portal (Akun Kisara) shows the PIC their sites, with a one-click
 * cPanel login.
 *
 * Reads the LOCAL whm accounts table, which SyncWhmAccountsJob has just
 * refreshed, instead of calling WHM again. It used to call listAccounts()
 * itself and only ran from `whm:sync-accounts --registry`, by hand — so in
 * practice the registry never saw new accounts. It now runs at the end of
 * every account sync.
 *
 * Flow per account:
 *  1. Already linked (package_ref=whm, external_id=username): refresh
 *     identifier and status.
 *  2. Else an unlinked asset with the same domain exists — hosting, or the
 *     site's (sub)domain registered by hand: attach package_ref +
 *     external_id, keep its PICs.
 *  3. Else create a new unassigned asset for an admin to give an OPD/PIC.
 *
 * Registry is a soft dependency — without it this does nothing.
 */
class AccountRegistrySync
{
    public const PACKAGE_REF = 'whm';

    public static function available(): bool
    {
        return class_exists(\Nawasara\Registry\Models\Asset::class);
    }

    /** @return array{total:int, created:int, linked:int, updated:int, unchanged:int, deactivated:int} */
    public function sync(): array
    {
        $stats = ['total' => 0, 'created' => 0, 'linked' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];

        if (! self::available()) {
            return $stats;
        }

        $asset = \Nawasara\Registry\Models\Asset::class;
        $accounts = WhmAccount::query()->whereNotNull('username')->whereNotNull('domain')->get();
        $stats['total'] = $accounts->count();
        $seen = [];

        foreach ($accounts as $acct) {
            $seen[] = $acct->username;
            $status = $acct->suspended ? 'inactive' : 'active';

            $row = $asset::where('package_ref', self::PACKAGE_REF)->where('external_id', $acct->username)->first();

            if ($row) {
                $changes = array_filter([
                    'identifier' => $row->identifier !== $acct->domain ? $acct->domain : null,
                    // Status only for hosting_account assets. A linked
                    // (sub)domain asset stays active while the account is
                    // suspended: Akun Kisara hides inactive assets, and the
                    // PIC needs to SEE the "hosting suspended" warning.
                    'status' => $row->type === 'hosting_account' && $row->status !== $status ? $status : null,
                ]);
                if ($changes) {
                    $row->update($changes);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }

                continue;
            }

            // domain/subdomain too: most agency sites were registered by hand
            // as a (sub)domain with their PIC already set. Linking that asset
            // carries the PIC over to cPanel; a separate hosting_account asset
            // would leave the site's PIC without cPanel until reassigned.
            $existing = $asset::whereIn('type', ['hosting_account', 'hosting', 'domain', 'subdomain'])
                ->where('identifier', $acct->domain)
                ->whereNull('external_id')
                ->first();

            if ($existing) {
                $existing->update(['package_ref' => self::PACKAGE_REF, 'external_id' => $acct->username]);
                $stats['linked']++;

                continue;
            }

            $asset::create([
                'opd_id' => null,
                'pj_user_id' => null,
                'type' => 'hosting_account',
                'identifier' => $acct->domain,
                'package_ref' => self::PACKAGE_REF,
                'external_id' => $acct->username,
                'status' => $status,
                'registered_at' => now(),
                'discovered_at' => now(),
                'notes' => 'Akun cPanel '.$acct->username.($acct->instance ? ' di '.$acct->instance : ''),
            ]);
            $stats['created']++;
        }

        // Guard: an empty table means nothing was synced yet, not that every
        // account was terminated — deactivating would empty Akun Kisara.
        if ($seen) {
            $stats['deactivated'] = $asset::where('package_ref', self::PACKAGE_REF)
                ->where('type', 'hosting_account')
                ->where('status', 'active')
                ->whereNotNull('external_id')
                ->whereNotIn('external_id', $seen)
                ->update(['status' => 'inactive']);
        }

        return $stats;
    }
}
