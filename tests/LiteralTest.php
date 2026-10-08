<?php

declare(strict_types=1);

namespace Golem\Tests;

use Golem\Attribute\DataProvider;
use Golem\Runtime\Fuzz\Literal;
use Golem\TestCase;

/**
 * The PHP code golem fuzz --write-tests writes for the values it sent.
 */
final class LiteralTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function values(): array
    {
        return [
            'null' => [null, 'null'],
            'bool' => [false, 'false'],
            'int' => [-1, '-1'],
            'float' => [1.5, '1.5'],
            'round float' => [2.0, '2.0'],
            'string' => ["it's", "'it\\'s'"],
            'percent' => ['%s', "'%s'"],
            'unicode' => ["\u{202E}§c", '"\\u{202E}\\u{A7}c"'],
            'dollar' => ["\u{1F642}\$x", '"\\u{1F642}\\$x"'],
            'list' => [[1, 'x', true], "[1, 'x', true]"],
            'map' => [['key' => [null]], "['key' => [null]]"],
        ];
    }

    #[DataProvider('values')]
    public function testWritesValuesAsCode(mixed $value, string $code): void
    {
        $this->assertSame($code, Literal::of($value));
        $this->assertSame($value, eval("return $code;"), 'the code must give back the value');
    }
}
