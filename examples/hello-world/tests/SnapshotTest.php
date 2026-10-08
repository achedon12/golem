<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\TestCase;

final class SnapshotTest extends TestCase
{
    public function testTheMenuForm(): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->chat('/menu');

        $this->assertMatchesSnapshot($steve->formData());
    }

    public function testTheLobbyScreen(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertMatchesSnapshot($steve->scoreboard(), 'sidebar');
        $this->assertMatchesSnapshot($steve->bossBar(), 'boss bar');
    }
}
