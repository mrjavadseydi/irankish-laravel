<?php

namespace MJSeydi\iranKish;

use Illuminate\Support\ServiceProvider;
use \MJSeydi\iranKish\IranKish;
class IranKishServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/IranKish.php', 'IranKish');
        $this->app->bind('IranKish', function($app) {
            return new IranKish();
        });
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
                __DIR__.'/config/IranKish.php' => config_path('IranKish.php'),
            ], 'config');

        }
    }
}
