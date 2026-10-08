---
description: "golem bench: golems join a PocketMine-MP server a few at a time while Golem measures TPS, tick usage and memory, and lists the plugin's slowest listeners."
---

# Benchmark

How many players can the server hold with your plugin? `golem bench` starts a server with it and
brings golems in, a few at a time. They walk around, jump and chat while Golem measures how the
server holds up, then lists the listeners and tasks of the plugin that took the most time.

```bash
vendor/bin/golem bench --players=100
```

```text
  Golem is benchmarking HelloWorld on PocketMine-MP 5.44.3: up to 100 players, 12s per step

  Players     TPS   Tick usage (avg / max)     Memory
       20    20.0       4.6% / 6.7%         102 MB
       40    20.0       7.7% / 12.7%        122 MB
       60    20.0      10.1% / 15.1%        155 MB
       80    20.0      13.8% / 20.0%        192 MB
      100    20.0      15.4% / 23.9%        250 MB

  Slowest listeners and tasks of HelloWorld
    Example\HelloWorld\HelloWorld->onJoin(PlayerJoinEvent)         100 calls     0.905 ms avg      90.5 ms total
    Example\HelloWorld\HelloWorld->onMove(PlayerMoveEvent)       25204 calls     0.002 ms avg      52.6 ms total
    Task: closure@src/HelloWorld#L97(Single)                       100 calls     0.034 ms avg       3.4 ms total

  The server stayed above 19.5 TPS up to 100 players.
```

- **TPS**: ticks per second while measuring. The server aims for 20; below that, players feel lag.
- **Tick usage**: the share of the 50 ms of each tick the server spent working, on average and at
  worst. At 100%, it can no longer keep up and the TPS drops.
- **Memory**: memory used by the server's main thread at the end of the step.
- **Slowest listeners and tasks**: from PocketMine's timings, only those of your plugin, sorted by
  the total time they took during the run.

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--players=<count>` | `20` | How many golems in the end, at most `200`. They come in 5 steps |
| `--duration=<seconds>` | `60` | How long to measure in all, split between the steps |
| `--min-tps=<tps>` | | Exit with `1` if the TPS falls below this at any step |
| `--save-baseline=<file>` | | Save the results as JSON, to compare later runs with |
| `--baseline=<file>` | | Compare with saved results and exit with `1` on a regression (see [Baselines](#baselines)) |

`--path`, `--pocketmine`, `--php`, `--phar` and `--verbose` work as for
[`golem run`](configuration.md#command-line).

## Reading the numbers

Golems do not behave exactly like real players and they run in the server process, so their own
cost is in the numbers. Use the benchmark to compare: before and after a change, one version of
the plugin against another, one server against another (`--pocketmine`). It is not an exact
player count for production.

Results vary with the machine. On a shared CI runner, keep `--min-tps` well below 20 so the job
only fails on a real slowdown:

```yaml
- run: vendor/bin/golem bench --players=30 --duration=30 --min-tps=15
```

## Baselines

Save the results of a run, then compare a later run with them:

```bash
vendor/bin/golem bench --players=50 --save-baseline=bench.json
# … change the plugin …
vendor/bin/golem bench --players=50 --baseline=bench.json
```

```text
  Compared with bench.json (LagPlugin, PocketMine-MP 5.44.3)
  Players     TPS            Tick usage avg       Memory
       10    20.0 (=)        12.3% (+7.9 pt)       96 MB (=)
       20    20.0 (=)        22.5% (+11.8 pt)     102 MB (+2 MB)
  ↑ lag\Main->onMove(PlayerMoveEvent) 0.263 → 1.075 ms avg (×4.1)

  3 performance regression(s).
```

A step regresses when the TPS drops by one or more, or when the tick usage grows by more than 30%
and at least 2 points; a listener or task when its average time grows by half and it took at least
a millisecond in all. Smaller differences are noise between runs. Steps are matched by number of
players, so use the same `--players` as the baseline.

Benchmarks only compare on the same machine. In a pull request, run both on the same runner:
first the target branch, then the pull request. The comparison also goes to the job summary.

```yaml
name: Benchmark
on: pull_request

jobs:
  bench:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          ref: ${{ github.base_ref }}
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction
      - run: vendor/bin/golem bench --players=30 --save-baseline=${{ runner.temp }}/bench.json

      - uses: actions/checkout@v7
      - run: composer install --no-interaction
      - run: vendor/bin/golem bench --players=30 --baseline=${{ runner.temp }}/bench.json
```

## In a test

`assertTpsAbove()` checks the server's average TPS over the last second, to catch a change that
makes a feature lag:

```php
public function testTwentyPlayersWalkingDoNotSlowTheServerDown(): Generator
{
    $golems = yield $this->golems(20);
    foreach ($golems as $i => $golem) {
        $golem->walk($i % 2 === 0 ? 10 : -10, 6); // a few seconds of walking
    }

    yield $this->wait(30); // measure while they walk

    $this->assertTpsAbove(18.0);
}
```

```text
   FAILED  LoadTest › twenty players walking do not slow the server down
  The server ran slower than 18 TPS

  expected  more than 18 TPS
  actual    11.83 TPS, tick usage 100%
```

Measure while the load is there: the average covers the last 20 ticks only, so asserting after
the golems stopped would measure an idle server.

## On a development server

`/golem spawn <name> <count>` brings a crowd onto your [development server](server-plugin.md):
`/golem spawn Bot 50` spawns `Bot1` to `Bot50`, one every other tick. Then watch `/status` or
`/timings`.
