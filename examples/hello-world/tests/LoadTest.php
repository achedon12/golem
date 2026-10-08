<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class LoadTest extends TestCase
{
    public function testTwentyPlayersWalkingDoNotSlowTheServerDown(): Generator
    {
        $golems = yield $this->golems(20);
        foreach ($golems as $i => $golem) {
            $golem->walk($i % 2 === 0 ? 10 : -10, 6); // a few seconds of walking
        }

        yield $this->wait(30); // measure while they walk: the average covers the last 20 ticks

        $this->assertTpsAbove(18.0);
    }

    public function testNamedGolemsJoinTogether(): Generator
    {
        [$steve, $alex] = yield $this->golems(['Steve', 'Alex']);

        $this->assertSame('Steve', $steve->name());
        $this->assertSame('Alex', $alex->name());
        $this->assertScoreboardContains($steve, 'Online: 2');
    }
}
