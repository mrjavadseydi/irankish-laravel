<?php

namespace MJSeydi\iranKish;

use Illuminate\Support\ServiceProvider;

class IranKishServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/src/config/IranKish.php', 'IranKish');
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {

            $this->publishes([
                __DIR__.'/src/config/IranKish.php' => config_path('IranKish.php'),
            ], 'config');

        }
    }
}
