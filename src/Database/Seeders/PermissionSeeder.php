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
