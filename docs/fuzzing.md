---
description: "golem fuzz: golems do random things to a PocketMine-MP plugin for a while and report every exception, with the actions that led to it and a seed to replay them."
---

# Fuzzing

Tests check what you thought of. `golem fuzz` looks for what you did not: it starts a server with
your plugin, connects a few golems, and has them do random things for a minute. Every exception
the plugin throws is reported, with the actions that led to it.

```bash
vendor/bin/golem fuzz
```

```text
  Golem is fuzzing HelloWorld on PocketMine-MP 5.44.3: 3 golems for 60s, seed 3
  commands: /heal, /kits, /menu
    1s · 1 actions · 0 crash(es)
    6s · 81 actions · 1 crash(es)
  …

   CRASH  TypeError: Cannot access offset of type array on array (×4)
  at src/MenuForm.php:30
  when Fuzz1 answered [null] to the form "Server menu"
  just before:
    · Fuzz2 answered false to the form "Server menu"
    · Fuzz3 ran /heal -99999999999 2147483648 null
    · Fuzz1 ran /kits

  Fuzzing: 912 actions, 1 problem(s)
  Replay:  vendor/bin/golem fuzz --seed=3 --duration=60 --golems=3
```

No test is needed: fuzzing works on any plugin with a `plugin.yml`.

## What the golems do

At random, every few ticks, each golem:

- runs one of the plugin's commands, with random arguments (numbers, names of other golems, long
  or empty strings, coordinates…). One golem is operator, the others are not, so commands are tried
  with and without permission;
- answers the form it was sent with a valid or invalid value: a button out of range, `null`, a
  string, an array with the wrong shape. A client can send any of these, so the plugin has to cope;
- clicks a slot of the inventory window it has open, or closes it;
- walks, jumps, sneaks, sprints, chats;
- breaks or right-clicks a block near it, attacks or right-clicks another golem;
- disconnects and comes back, or respawns after dying.

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--duration=<seconds>` | `60` | How long to fuzz, at least `5` |
| `--golems=<count>` | `3` | How many golems, at most `20` |
| `--seed=<n>` | random | The seed of the random choices |
| `--write-tests` | | Write a test that replays each crash (see [below](#turning-a-crash-into-a-test)) |

`--path`, `--pocketmine`, `--php`, `--phar` and `--verbose` work as for
[`golem run`](configuration.md#command-line), and the `extra.golem` settings of `composer.json`
apply.

## Reading the report

The same exception thrown from the same line is reported once, with the number of times it was
seen (`×4`), the action that threw it and the last actions before it. If the server itself
crashes, Golem prints the end of its log and stops.

The exit code is `1` when an exception was thrown or the server crashed, `0` otherwise, so
fuzzing can run in CI:

```yaml
- run: vendor/bin/golem fuzz --duration=30 --seed=1
```

A fixed seed makes the golems choose the same actions in the same order. The server is not fully
deterministic (timings, tasks), so a replay usually, not always, finds the same crash.

## Turning a crash into a test

With `--write-tests`, Golem writes a test next to yours for each crash, replaying every action of
the run up to the one that threw, with the same values and the same pauses:

```text
  Wrote tests/FuzzMenuForm30Test.php to replay it
```

```php
/**
 * Found by golem fuzz --seed=3: TypeError: Cannot access offset of type array on array
 * at src/MenuForm.php:30, when Fuzz1 answered [null] to the form "Server menu".
 */
final class FuzzMenuForm30Test extends TestCase
{
    #[Timeout(231)]
    public function testTypeErrorAtMenuForm30(): Generator
    {
        [$fuzz1, $fuzz2, $fuzz3] = yield $this->golems(['Fuzz1', 'Fuzz2', 'Fuzz3']);
        $fuzz1->op();

        $fuzz2->command('heal');
        $fuzz3->command('heal 1.5 Fuzz3 %s');
        yield $this->wait(1);
        // …
        $fuzz1->command('menu');
        yield $this->wait(1);
        $fuzz1->submitForm([null]); // throws TypeError
    }
}
```

The test fails with the crash until it is fixed. Most of the actions usually do not matter: remove
them until only what leads to the crash is left (here, `/menu` then the invalid answer), and
replace the crash with an assertion of what should happen, so it stays fixed.
