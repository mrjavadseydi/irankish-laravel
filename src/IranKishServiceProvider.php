<?php

namespace MJSeydi\iranKish;

use Illuminate\Support\ServiceProvider;

class IranKishServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/IranKish.php', 'IranKish');

        $this->app->singleton(IranKish::class, fn () => new IranKish());
        $this->app->alias(IranKish::class, 'IranKish');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/IranKish.php' => config_path('IranKish.php'),
            ], ['irankish-config', 'config']);
        }
    }
}
