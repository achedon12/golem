<?php

declare(strict_types=1);

namespace Golem\Runtime;

/**
 * Services shared by every test of a run. Set up once by {@see GolemPlugin}.
 *
 * @internal
 */
final class Runtime
{
    private static ?self $instance = null;

    public function __construct(
        public readonly GolemPlugin $plugin,
        public readonly GolemFactory $golems,
        public readonly Clock $clock,
        public readonly Worlds $worlds,
        public readonly string $subject,
        public readonly bool $updateSnapshots = false,
        public readonly bool $ci = false,
        public readonly bool $coverage = false,
        /** report the lines each test ran (golem mutate) */
        public readonly bool $perTestCoverage = false,
        /** stop at the first test that fails */
        public readonly bool $stopOnFailure = false,
        public readonly string $pluginRoot = '',
    ) {
    }

    public static function install(self $runtime): void
    {
        self::$instance = $runtime;
    }

    public static function get(): self
    {
        return self::$instance ?? throw new \LogicException(
            'Golem tests run inside a PocketMine-MP server. Start them with `vendor/bin/golem`, not PHPUnit.',
        );
    }
}
