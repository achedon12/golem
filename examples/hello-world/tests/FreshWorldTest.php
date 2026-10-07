<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class FreshWorldTest extends TestCase
{
    public function testGolemsSpawnInTheFreshWorld(): Generator
    {
        $shared = $this->world();

        $fresh = $this->freshWorld();
        $steve = yield $this->golem('Steve');

        $this->assertNotSame($shared, $fresh);
        $this->assertSame($fresh, $this->world());
        $this->assertSame($fresh, $steve->position()->getWorld());
    }

    public function testTheSharedWorldIsBackAfterwards(): void
    {
        $this->assertSame('golem', $this->world()->getFolderName());
    }
}
