<?php

namespace Nawasara\Whm\Database\Seeders;

use Illuminate\Database\Seeder;
use Nawasara\Core\Constants\Constants;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'whm.account.view',
            'whm.account.create',
            'whm.account.suspend',
            'whm.account.terminate',
            'whm.account.manage',
            'whm.package.view',
            'whm.package.manage',
            'whm.server.view',
            'whm.server.manage',
            'whm.sync.execute',

            // Email management (Day 2)
            'whm.email.view',
            'whm.email.create',
            'whm.email.manage',

            // Mail queue (Day 3)
            'whm.mailqueue.view',
            'whm.mailqueue.manage',

            // Mail log (Day 5)
            'whm.maillog.view',

            // Email stats (Day 4)
            'whm.emailstats.view',

            // Spam (Day 6)
            'whm.spam.view',
            'whm.spam.manage',

            // SSH gating
            'whm.ssh.execute',

            // Webmail session (create_user_session API)
            'whm.session.create',

            // ----------------------------------------------------------------
            // Webmail SSO bridge — owned by whm because the WHM API
            // (`create_user_session`) is what mints the Roundcube session
            // token. Lives here, not in core, even though the launch button
            // is rendered by core's WebmailLaunchController.
            // ----------------------------------------------------------------

            // User-facing webmail auto-login. Default-attached to guest +
            // developer below so all auto-provisioned ASN users can use it.
            'webmail.session.launch',

            // Admin-only audit log viewer for webmail launch events.
            // Separate from `webmail.session.launch` so compliance reviewers
            // can inspect access without inheriting the ability to launch.
            'webmail.session.audit.view',

            // Admin impersonation: buka webmail user manapun tanpa tahu
            // password. Sensitive — JANGAN di-attach ke role default. Manual
            // assign per user admin via Setting → Role Management. Audit log
            // mandatory di nawasara_webmail_sessions (launch_kind=impersonation,
            // reason wajib).
            'webmail.session.launch_as',

            // Admin impersonation: buka cPanel akun user manapun tanpa tahu
            // password. Sister-permission dari webmail.session.launch_as tapi
            // grant terpisah karena cPanel = full hosting control (file
            // manager, DB access, dll) sedangkan webmail cuma read/send
            // email. Admin bisa punya akses webmail tapi tidak cPanel, atau
            // sebaliknya. Audit di nawasara_cpanel_sessions, reason wajib min
            // 10 char.
            'whm.cpanel.launch_as',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $role = Role::where('name', 'developer')->first();

        if ($role) {
            $role->givePermissionTo($permissions);
        }

        // Webmail launch is the entry point for all SSO-provisioned ASN users
        // (auto-assigned to `guest`), and obviously also for `developer`.
        // Run AFTER the developer block above so we don't double-assign the
        // launch perm to developer — givePermissionTo is idempotent anyway.
        $launchPerm = 'webmail.session.launch';
        foreach (['guest', Constants::DEFAULT_ROLE] as $roleName) {
            $r = Role::where('name', $roleName)->first();
            if ($r) {
                $r->givePermissionTo($launchPerm);
            }
        }
    }
}
