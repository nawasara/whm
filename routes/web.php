<?php

use Illuminate\Support\Facades\Route;
use Nawasara\Whm\Livewire\Account\Index as AccountIndex;
use Nawasara\Whm\Livewire\Email\Index as EmailIndex;
use Nawasara\Whm\Livewire\EmailStats\Index as EmailStatsIndex;
use Nawasara\Whm\Livewire\MailLog\Index as MailLogIndex;
use Nawasara\Whm\Livewire\MailQueue\Index as MailQueueIndex;
use Nawasara\Whm\Livewire\Package\Index as PackageIndex;
use Nawasara\Whm\Livewire\Server\Index as ServerIndex;
use Nawasara\Whm\Livewire\Usage\Index as UsageIndex;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('nawasara-whm')->group(function () {
    Route::get('accounts', AccountIndex::class)
        ->middleware(PermissionMiddleware::using('whm.account.view'))
        ->name('nawasara-whm.account.index');

    Route::get('usage', UsageIndex::class)
        ->middleware(PermissionMiddleware::using('whm.account.view'))
        ->name('nawasara-whm.usage.index');

    Route::get('packages', PackageIndex::class)
        ->middleware(PermissionMiddleware::using('whm.package.view'))
        ->name('nawasara-whm.package.index');

    Route::get('email', EmailIndex::class)
        ->middleware(PermissionMiddleware::using('whm.email.view'))
        ->name('nawasara-whm.email.index');

    Route::get('mail-queue', MailQueueIndex::class)
        ->middleware(PermissionMiddleware::using('whm.mailqueue.view'))
        ->name('nawasara-whm.mail-queue.index');

    Route::get('mail-log', MailLogIndex::class)
        ->middleware(PermissionMiddleware::using('whm.maillog.view'))
        ->name('nawasara-whm.mail-log.index');

    Route::get('email-stats', EmailStatsIndex::class)
        ->middleware(PermissionMiddleware::using('whm.emailstats.view'))
        ->name('nawasara-whm.email-stats.index');

    Route::get('server', ServerIndex::class)
        ->middleware(PermissionMiddleware::using('whm.server.view'))
        ->name('nawasara-whm.server.index');
});
