<?php

declare(strict_types=1);

namespace Golem\Runtime;

/**
 * The order tests run in: as discovered, or shuffled with a seed to find tests that depend
 * on the ones before them, and repeated to find tests that only fail sometimes.
 *
 * @internal
 */
final class TestOrder
{
    /**
     * @param list<TestDefinition> $tests
     * @return list<TestDefinition>
     */
    public static function arrange(array $tests, int $repeat, ?int $seed): array
    {
        $runs = [];
        for ($repetition = 1; $repetition <= max(1, $repeat); $repetition++) {
            $round = $tests;
            if ($seed !== null) {
                // a different order on every round, the same for a given seed
                $round = (new \Random\Randomizer(new \Random\Engine\Mt19937($seed + $repetition - 1)))->shuffleArray($round);
            }
            foreach ($round as $test) {
                $runs[] = $repetition === 1 ? $test : $test->withRepetition($repetition);
            }
        }

        return $runs;
    }
}
