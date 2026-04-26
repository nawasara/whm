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
use Nawasara\Whm\Services\EximClient;
use Nawasara\Whm\Services\SshConnection;
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
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-whm');
        $this->registerLivewire();

        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }

            $schedule = $this->app->make(Schedule::class);

            $schedule->command('whm:sync-accounts')
                ->everyThirtyMinutes()
                ->withoutOverlapping(25)
                ->runInBackground();

            // Sync email accounts dari WHM ke DB snapshot — every hour
            $schedule->command('whm:sync-emails')
                ->hourly()
                ->withoutOverlapping(50)
                ->runInBackground();

            // Heavy disk usage sync — daily at 02:00 (jangan ganggu jam kerja)
            $schedule->command('whm:sync-emails --with-disk')
                ->dailyAt('02:00')
                ->withoutOverlapping(60)
                ->runInBackground();
        });
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-whm.php', 'nawasara-whm');

        $this->app->singleton(WhmClient::class, fn () => new WhmClient());
        $this->app->singleton(SshConnection::class, fn () => new SshConnection());
        $this->app->singleton(EximClient::class, fn ($app) => new EximClient($app->make(SshConnection::class)));
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
