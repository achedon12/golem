<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Attribute\World;
use Golem\TestCase;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;

#[World('tests/worlds/arena')]
final class ArenaWorldTest extends TestCase
{
    public function testGolemsSpawnInTheArena(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertSame($this->world(), $steve->position()->getWorld());
        $this->assertBlockAt($this->goldMarker(), VanillaBlocks::GOLD());
    }

    public function testEachTestGetsAnUntouchedCopy(): Generator
    {
        // the previous test may have run in its own copy: the marker is always there
        $this->assertBlockAt($this->goldMarker(), VanillaBlocks::GOLD());

        $this->world()->setBlock($this->goldMarker(), VanillaBlocks::AIR());
        $this->assertBlockAt($this->goldMarker(), VanillaBlocks::AIR());
        yield $this->wait(1);
    }

    public function testTheTemplateWasNotModified(): void
    {
        $this->assertBlockAt($this->goldMarker(), VanillaBlocks::GOLD());
    }

    private function goldMarker(): Vector3
    {
        return $this->spawn()->floor()->down()->add(3, 0, 0);
    }
}
