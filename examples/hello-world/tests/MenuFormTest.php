<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Golem;
use Golem\TestCase;

final class MenuFormTest extends TestCase
{
    private Golem $steve;

    protected function setUp(): Generator
    {
        $this->steve = yield $this->golem('Steve');
        $this->steve->chat('/menu');
    }

    public function testOpensTheMenu(): void
    {
        $this->assertFormOpen($this->steve, 'Server menu');
        $this->assertCount(2, $this->steve->formData()['buttons']);
    }

    public function testSpawnButtonTeleportsBackToSpawn(): void
    {
        $this->steve->teleport($this->spawn()->add(40, 0, 40));

        $this->steve->clickButton('Spawn');

        $this->assertNoFormOpen($this->steve);
        $this->assertAt($this->steve, $this->spawn());
        $this->assertSame('Teleported to spawn.', $this->steve->lastMessage());
    }

    public function testClosingTheMenuDoesNothing(): void
    {
        $before = $this->steve->messages();

        $this->steve->closeForm();

        $this->assertNoFormOpen($this->steve);
        $this->assertSame($before, $this->steve->messages());
    }
}
