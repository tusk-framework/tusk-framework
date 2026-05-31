<?php

namespace Tusk\Core\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Cache\ArrayCache;
use Tusk\Core\Cache\CacheManager;

class CacheManagerTest extends TestCase
{
    public function test_default_store_is_array_cache(): void
    {
        $manager = new CacheManager();
        
        $this->assertInstanceOf(ArrayCache::class, $manager->store());
        $this->assertInstanceOf(ArrayCache::class, $manager->store('array'));
    }

    public function test_can_add_custom_store(): void
    {
        $manager = new CacheManager();
        $customStore = new ArrayCache(); // For testing, just use another array cache
        
        $manager->addStore('custom', $customStore);
        
        $this->assertSame($customStore, $manager->store('custom'));
    }

    public function test_throws_exception_on_invalid_store(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $manager = new CacheManager();
        $manager->store('redis');
    }

    public function test_proxies_calls_to_default_store(): void
    {
        $manager = new CacheManager();
        
        $this->assertTrue($manager->set('key', 'value'));
        $this->assertTrue($manager->has('key'));
        $this->assertEquals('value', $manager->get('key'));
        
        $manager->delete('key');
        $this->assertFalse($manager->has('key'));
    }
}
