<?php

declare(strict_types=1);

namespace Bekambeyene\Telebirr\Providers;

use Illuminate\Support\ServiceProvider;
use Bekambeyene\Telebirr\TelebirrClient;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Contracts\TelebirrClientInterface;

class TelebirrServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/telebirr.php', 'telebirr');

        $this->app->singleton(SignatureService::class, function () {
            return new SignatureService();
        });

        $this->app->singleton(TelebirrHttpClient::class, function ($app) {
            $config = $app['config']['telebirr'];
            $baseUrl = $config['base_url'] ?? '';
            
            // Allow environment-based toggle or explicit config for SSL verification
            $verifySsl = $config['ssl_verify'] ?? $app->environment('production');

            return new TelebirrHttpClient($baseUrl, (bool)$verifySsl);
        });

        $this->app->singleton(TokenManager::class, function ($app) {
            $config = $app['config']['telebirr'];
            $fabricAppId = $config['fabric_app_id'] ?? '';
            $appSecret = $config['app_secret'] ?? '';

            return new TokenManager(
                $app->make(TelebirrHttpClient::class),
                $fabricAppId,
                $appSecret
            );
        });

        $this->app->singleton(TelebirrClientInterface::class, function ($app) {
            $config = $app['config']['telebirr'] ?? [];
            
            return new TelebirrClient(
                $config,
                $app->make(TokenManager::class),
                $app->make(SignatureService::class),
                $app->make(TelebirrHttpClient::class),
                function($message, $level = 'info', $context = []) use ($app) {
                    if ($app->bound('log')) {
                        $app->make('log')->log($level, $message, $context);
                    }
                }
            );
        });

        $this->app->alias(TelebirrClientInterface::class, 'telebirr');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/telebirr.php' => config_path('telebirr.php'),
            ], 'telebirr-config');
        }
    }
}
