<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Attribute\FreshWorld;
use Golem\TestCase;
use pocketmine\block\VanillaBlocks;

final class MovementTest extends TestCase
{
    public function testWalkingIntoTheArenaAnnouncesIt(): Generator
    {
        $steve = yield $this->golem('Steve');
        $start = $steve->position();

        yield $steve->walk(22, 0);

        $this->assertReceivedMessage($steve, 'You entered the arena.');
        $this->assertAt($steve, $start->add(22, 0, 0), 0.5);
    }

    #[FreshWorld]
    public function testWallsStopGolems(): Generator
    {
        $steve = yield $this->golem('Steve');
        $feet = $steve->position()->floor();
        for ($z = -3; $z <= 3; $z++) {
            $this->world()->setBlock($feet->add(3, 0, $z), VanillaBlocks::STONE());
            $this->world()->setBlock($feet->add(3, 1, $z), VanillaBlocks::STONE());
        }

        try {
            yield $steve->walk(6, 0);
            $this->fail('The golem walked through a wall');
        } catch (\RuntimeException $e) {
            $this->assertContains('is stuck', $e->getMessage());
        }
        $this->assertLessThan($feet->x + 3, $steve->position()->x);
    }

    #[FreshWorld]
    public function testFallingHurts(): Generator
    {
        $steve = yield $this->golem('Steve');
        yield $this->wait(60); // players cannot be hurt for 3 seconds after joining
        $steve->teleport($steve->position()->add(0, 10, 0));

        yield $this->waitUntil(fn () => $steve->player()->getHealth() < 20, 60, 'the golem to land and get hurt');

        $this->assertEquals(13, $steve->player()->getHealth(), 'a 10 block fall costs 7 health');
    }

    public function testJumpingGoesUpThenDown(): Generator
    {
        $steve = yield $this->golem('Steve');
        $ground = $steve->position()->y;

        $this->assertTrue($steve->jump());
        yield $this->wait(4);
        $this->assertGreaterThan($ground + 0.5, $steve->position()->y);

        yield $this->wait(20);
        $this->assertEquals($ground, $steve->position()->y);
    }
}
