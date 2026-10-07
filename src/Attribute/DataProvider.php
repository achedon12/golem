<?php

declare(strict_types=1);

namespace Golem\Attribute;

/**
 * Runs a test once per data set returned by a public static method of the test class.
 *
 *     #[DataProvider('ranks')]
 *     public function testRankPrefix(string $rank, string $prefix): Generator { ... }
 *
 *     public static function ranks(): iterable
 *     {
 *         yield 'admin' => ['admin', '[Admin]'];
 *         yield 'vip' => ['vip', '[VIP]'];
 *     }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class DataProvider
{
    public function __construct(public readonly string $method)
    {
    }
}
