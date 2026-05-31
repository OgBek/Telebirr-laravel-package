<?php

namespace Bekambeyene\Telebirr\Tests\Laravel;

use Bekambeyene\Telebirr\Contracts\TelebirrClientInterface;
use Bekambeyene\Telebirr\Facades\Telebirr;
use Bekambeyene\Telebirr\Services\SignatureService;
use Bekambeyene\Telebirr\Services\TelebirrHttpClient;
use Bekambeyene\Telebirr\Services\TokenManager;
use Bekambeyene\Telebirr\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_services_are_bound_to_container()
    {
        $this->assertTrue($this->app->bound(SignatureService::class));
        $this->assertTrue($this->app->bound(TelebirrHttpClient::class));
        $this->assertTrue($this->app->bound(TokenManager::class));
        $this->assertTrue($this->app->bound(TelebirrClientInterface::class));
        
        $this->assertInstanceOf(SignatureService::class, $this->app->make(SignatureService::class));
        $this->assertInstanceOf(TelebirrHttpClient::class, $this->app->make(TelebirrHttpClient::class));
        $this->assertInstanceOf(TokenManager::class, $this->app->make(TokenManager::class));
        $this->assertInstanceOf(TelebirrClientInterface::class, $this->app->make(TelebirrClientInterface::class));
    }

    public function test_facade_resolves_telebirr_client()
    {
        $resolved = Telebirr::getFacadeRoot();
        
        $this->assertInstanceOf(TelebirrClientInterface::class, $resolved);
    }
}
