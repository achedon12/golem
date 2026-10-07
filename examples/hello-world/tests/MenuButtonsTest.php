<?php

declare(strict_types=1);

namespace Example\HelloWorld\Tests;

use Generator;
use Golem\Attribute\DataProvider;
use Golem\TestCase;

final class MenuButtonsTest extends TestCase
{
    #[DataProvider('buttons')]
    public function testEveryMenuButtonAnswers(string $button, string $reply): Generator
    {
        $steve = yield $this->golem('Steve');
        $steve->chat('/menu');

        $steve->clickButton($button);

        $this->assertSame($reply, $steve->lastMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function buttons(): iterable
    {
        yield 'spawn' => ['Spawn', 'Teleported to spawn.'];
        yield 'daytime' => ['Daytime', 'Good morning!'];
    }
}
