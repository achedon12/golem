<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class HealCommandTest extends TestCase
{
    public function testOperatorsCanHealThemselves(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->op();
        $steve->player()->setHealth(4);

        $steve->chat('/heal');

        $this->assertHealth($steve, 20);
        $this->assertReceivedMessage($steve, 'You have been healed.');

        // played in the world, so every nearby player hears it once the tick ends
        yield $this->wait(1);
        $this->assertSoundPlayed($steve, 'levelup');
    }

    public function testRegularPlayersCannot(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->player()->setHealth(4);

        $steve->chat('/heal');

        $this->assertHealth($steve, 4);
        $this->assertNotReceivedMessage($steve, 'You have been healed.');
    }
}
