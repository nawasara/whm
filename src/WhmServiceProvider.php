<?php

namespace Nawasara\Whm;

use Livewire\Livewire;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\ServiceProvider;
use Nawasara\Whm\Console\Commands\SyncAccountsCommand;
use Nawasara\Whm\Console\Commands\SyncEmailsCommand;
use Nawasara\Whm\Jobs\Account\SyncWhmAccountsJob;
use Nawasara\Whm\Jobs\Email\SyncWhmEmailsJob;
use Nawasara\Whm\Services\EmailStatsAggregator;
use Nawasara\Whm\Services\EximClient;
use Nawasara\Whm\Services\MailSecurityAggregator;
use Nawasara\Whm\Services\SshConnection;
use Nawasara\Whm\Services\WebmailSessionService;
use Nawasara\Whm\Services\WhmClient;

class WhmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register commands FIRST (before any potentially-failing operation)
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncAccountsCommand::class,
                SyncEmailsCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-whm');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // Guarded — Laravel's view:cache crashes on missing registered paths.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-whm');
        }
        $this->registerLivewire();

        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            // Skip scheduler registration when disabled — e.g. deployment
            // without WHM API credentials, where these tasks would only
            // fail every run and spam the log.
            if (! config('nawasara-whm.scheduler.enabled', true)) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);

            // Dispatch jobs via $schedule->call() — NOT $schedule->command().
            // Package console commands registered through $this->commands() do
            // not reliably surface in the Artisan kernel when the scheduler
            // boots, so `$schedule->command('whm:sync-accounts')` silently dies
            // with "no commands defined in the whm namespace" every run (the
            // task fires, php artisan errors, output is sent to /dev/null →
            // sync never happens, no failed_jobs row). Dispatching the job
            // straight from the scheduler process avoids the kernel lookup.
            // Mirrors the command handlers: loop instances by role, queue one
            // job each. See reference: schedule via $schedule->call().
            $schedule->call(function () {
                $whm = $this->app->make(WhmClient::class);
                foreach ($whm->instancesByRole('hosting') as $name) {
                    SyncWhmAccountsJob::dispatch(
                        instance: $name,
                        triggerSource: 'scheduled',
                    );
                }
            })
                ->name('whm:sync-accounts')
                ->everyThirtyMinutes()
                ->withoutOverlapping(25);

            // Sync email accounts dari WHM ke DB snapshot — every hour
            $schedule->call(function () {
                $whm = $this->app->make(WhmClient::class);
                foreach ($whm->instancesByRole('mail') as $name) {
                    SyncWhmEmailsJob::dispatch(
                        instance: $name,
                        payload: ['with_disk' => false],
                        triggerSource: 'scheduled',
                    );
                }
            })
                ->name('whm:sync-emails')
                ->hourly()
                ->withoutOverlapping(50);

            // Heavy disk usage sync — daily at 02:00 (jangan ganggu jam kerja)
            $schedule->call(function () {
                $whm = $this->app->make(WhmClient::class);
                foreach ($whm->instancesByRole('mail') as $name) {
                    SyncWhmEmailsJob::dispatch(
                        instance: $name,
                        payload: ['with_disk' => true],
                        triggerSource: 'scheduled',
                    );
                }
            })
                ->name('whm:sync-emails-disk')
                ->dailyAt('02:00')
                ->withoutOverlapping(60);
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-whm.php', 'nawasara-whm');

        $this->app->singleton(WhmClient::class, fn () => new WhmClient());
        $this->app->singleton(SshConnection::class, fn () => new SshConnection());
        $this->app->singleton(EximClient::class, fn ($app) => new EximClient($app->make(SshConnection::class)));
        $this->app->singleton(EmailStatsAggregator::class, fn ($app) => new EmailStatsAggregator($app->make(SshConnection::class)));
        $this->app->singleton(MailSecurityAggregator::class, fn ($app) => new MailSecurityAggregator($app->make(SshConnection::class)));

        // WebmailSessionService = thin orchestrator over WhmClient untuk forge
        // one-shot session URL via WHM `create_user_session`. Dipakai oleh:
        //   - Self-launch user-facing (WebmailLaunchController di nawasara-core)
        //   - Admin impersonation (AdminWebmailLaunchController di nawasara-whm)
        $this->app->singleton(WebmailSessionService::class, fn ($app) => new WebmailSessionService(
            $app->make(WhmClient::class),
        ));
    }

    public function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Whm\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-whm.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
