<?php

namespace Nawasara\Whm\Database\Seeders;

use Illuminate\Database\Seeder;
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

            // Admin impersonation: buka webmail user manapun tanpa tahu password.
            // Sensitive — JANGAN di-attach ke role default. Manual assign per user
            // admin via Setting → Role Management. Audit log mandatory di
            // nawasara_webmail_sessions (launch_kind=impersonation, reason wajib).
            'webmail.session.launch_as',
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
    }
}
