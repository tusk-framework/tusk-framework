<?php

namespace Tusk\Runtime\Tests\RoadRunner;

use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\KeyValue\StorageInterface;
use Tusk\Runtime\RoadRunner\RoadRunnerKv;

final class RoadRunnerKvTest extends TestCase
{
    public function test_it_maps_values_and_ttl_to_the_roadrunner_storage(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('get')->with('user:1', null)->willReturn(['id' => 1]);
        $storage->expects(self::once())->method('set')->with('user:1', ['id' => 2], 60)->willReturn(true);
        $storage->expects(self::once())->method('has')->with('user:1')->willReturn(true);
        $storage->expects(self::once())->method('delete')->with('user:1')->willReturn(true);
        $storage->expects(self::once())->method('clear')->willReturn(true);

        $kv = new RoadRunnerKv($storage);

        self::assertSame(['id' => 1], $kv->get('user:1'));
        $kv->set('user:1', ['id' => 2], 60);
        self::assertTrue($kv->has('user:1'));
        $kv->delete('user:1');
        $kv->clear();
    }
}
