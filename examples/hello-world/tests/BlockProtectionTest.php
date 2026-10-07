<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Attribute\FreshWorld;
use Golem\Golem;
use Golem\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;

final class BlockProtectionTest extends TestCase
{
    public function testVisitorsCannotBreakBlocks(): Generator
    {
        $steve = yield $this->golem('Steve');
        $target = $this->blockUnder($steve);
        $before = $this->world()->getBlock($target);

        $this->assertFalse($steve->breakBlock($target));

        $this->assertBlockAt($target, $before);
        $this->assertReceivedMessage($steve, 'You cannot build here.');
    }

    #[FreshWorld] // this test digs a hole: keep it out of the shared world
    public function testBuildersCan(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->gamemode(GameMode::SURVIVAL)->grant('hello.build');
        $target = $this->blockUnder($steve);

        $this->assertTrue($steve->breakBlock($target));

        $this->assertBlockAt($target, VanillaBlocks::AIR());
    }

    private function blockUnder(Golem $golem): Vector3
    {
        return $golem->position()->floor()->down();
    }
}
