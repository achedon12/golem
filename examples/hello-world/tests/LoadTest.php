<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Attribute\Timeout;
use Golem\TestCase;

final class LoadTest extends TestCase
{
    #[Timeout(600)] // golems join one after the other, about 10 ticks each
    public function testTwentyPlayersWalkingDoNotSlowTheServerDown(): Generator
    {
        $golems = [];
        for ($i = 1; $i <= 20; $i++) {
            $golems[] = yield $this->golem("Walker$i");
        }
        foreach ($golems as $i => $golem) {
            $golem->walk($i % 2 === 0 ? 10 : -10, 6); // a few seconds of walking
        }

        yield $this->wait(30); // measure while they walk: the average covers the last 20 ticks

        $this->assertTpsAbove(18.0);
    }
}
