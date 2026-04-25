<?php

$prefix = 'nawasara-whm';

return [
    [
        'label' => 'WHM Hosting',
        'icon' => 'lucide-server',
        'url' => '',
        'permission' => 'whm.account.view',
        'submenu' => [
            [
                'label' => 'Accounts',
                'icon' => 'lucide-users',
                'url' => url($prefix.'/accounts'),
                'permission' => 'whm.account.view',
                'navigate' => true,
            ],
            [
                'label' => 'Usage Dashboard',
                'icon' => 'lucide-gauge',
                'url' => url($prefix.'/usage'),
                'permission' => 'whm.account.view',
                'navigate' => true,
            ],
            [
                'label' => 'Packages',
                'icon' => 'lucide-package',
                'url' => url($prefix.'/packages'),
                'permission' => 'whm.package.view',
                'navigate' => true,
            ],
            [
                'label' => 'Email Accounts',
                'icon' => 'lucide-mail',
                'url' => url($prefix.'/email'),
                'permission' => 'whm.email.view',
                'navigate' => true,
            ],
            [
                'label' => 'Server Status',
                'icon' => 'lucide-activity',
                'url' => url($prefix.'/server'),
                'permission' => 'whm.server.view',
                'navigate' => true,
            ],
        ],
    ],
];
