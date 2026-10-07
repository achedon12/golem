<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class KickTest extends TestCase
{
    public function testOperatorsCanKickPlayers(): Generator
    {
        $admin = yield $this->golem('Admin');
        $steve = yield $this->golem('Steve');
        $admin->op();

        $admin->chat('/kick Steve griefing');

        $this->assertKicked($steve, 'griefing');
        $this->assertOnline($admin);
    }

    public function testQuittingRecordsTheReason(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertNull($steve->disconnectReason());
        $steve->quit('Bye');

        $this->assertOffline($steve);
        $this->assertSame('Bye', $steve->disconnectReason());
    }
}
