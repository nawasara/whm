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
                'label' => 'Mail Queue',
                'icon' => 'lucide-inbox',
                'url' => url($prefix.'/mail-queue'),
                'permission' => 'whm.mailqueue.view',
                'navigate' => true,
            ],
            [
                'label' => 'Mail Log',
                'icon' => 'lucide-scroll-text',
                'url' => url($prefix.'/mail-log'),
                'permission' => 'whm.maillog.view',
                'navigate' => true,
            ],
            [
                'label' => 'Email Stats',
                'icon' => 'lucide-bar-chart-3',
                'url' => url($prefix.'/email-stats'),
                'permission' => 'whm.emailstats.view',
                'navigate' => true,
            ],
            [
                'label' => 'Mail Security',
                'icon' => 'lucide-shield-x',
                'url' => url($prefix.'/mail-security'),
                'permission' => 'whm.spam.view',
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
