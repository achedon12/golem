<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class LobbyHudTest extends TestCase
{
    public function testShowsTheLobbySidebar(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertSame(
            ['title' => 'HelloWorld', 'lines' => ['Hi, Steve', 'Online: 1']],
            $steve->scoreboard(),
        );
    }

    public function testCountsPlayersOnline(): Generator
    {
        $steve = yield $this->golem('Steve');
        yield $this->golem('Alex');

        $this->assertScoreboardContains($steve, 'Online: 2');
    }

    public function testShowsAWelcomeBossBar(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertBossBar($steve, 'Welcome to HelloWorld', 1.0);
    }
}
