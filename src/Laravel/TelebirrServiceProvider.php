<?php

namespace Bekambeyene\Telebirr\Laravel;

use Illuminate\Support\ServiceProvider;
use Bekambeyene\Telebirr\TelebirrClient;
use Illuminate\Support\Facades\Log;

class TelebirrServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/telebirr.php', 'telebirr'
        );

        $this->app->singleton(TelebirrClient::class, function ($app) {
            $config = $app['config']->get('telebirr');
            
            // Pass a logging callable that bridges TelebirrClient to Laravel's Log facade
            $logger = function (string $message, string $level = 'info', array $context = []) {
                Log::log($level, $message, $context);
            };

            return new TelebirrClient($config, $logger);
        });

        $this->app->alias(TelebirrClient::class, 'telebirr');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/telebirr.php' => config_path('telebirr.php'),
            ], 'telebirr-config');
        }
    }
}
