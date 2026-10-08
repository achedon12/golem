---
description: "golem mutate: mutation testing for PocketMine-MP plugins. Golem changes your code one small mutation at a time and checks that your tests notice."
---

# Mutation testing

Coverage tells you which lines your tests run, not whether they would notice a bug there. A test
can run a line and check nothing about it. `golem mutate` finds out: it changes your plugin's code
one small mutation at a time, and runs your tests on each changed version (a *mutant*). If no
test fails, the mutant *survived*: that bug would get through.

```bash
vendor/bin/golem mutate
```

```text
  Golem is mutating HelloWorld: running the tests once with coverage, to see which lines each test runs…
  17 mutant(s) to try on 2 server(s) side by side
  ..M.M.MMMM.M....M

  ✗ survived src/HelloWorld.php:110 < → <=
    - if ($event->getFrom()->x < $arenaStart && $event->getTo()->x >= $arenaStart) {
    + if ($event->getFrom()->x <= $arenaStart && $event->getTo()->x >= $arenaStart) {
  ✗ survived src/HelloWorld.php:140 true → false
    - return true;
    + return false;

  Mutation score: 52.9% (9 of 17 mutants caught by the tests)
  Not tried:      8 mutation(s) on lines no test runs (see --coverage)
```

Each survivor is a question to ask yourself: here, no test walks a player onto the exact first
block of the arena, and no test checks what `/heal` returns. Add a test that would fail with the
mutant, and run `golem mutate` again.

## The mutations

| Change | Example |
| --- | --- |
| Equality | `===` ↔ `!==`, `==` ↔ `!=` |
| Comparison | `<` ↔ `<=`, `>` ↔ `>=` |
| Logic | `&&` ↔ `\|\|`, `and` ↔ `or`, `!$x` → `$x` |
| Arithmetic | `+` ↔ `-`, `*` ↔ `/` |
| Booleans | `true` ↔ `false` |

## How it stays fast

- Golem first runs your tests once with line coverage (Xdebug ships with PocketMine's PHP), to
  know which tests run each line.
- A mutant only runs the tests that run its line, and stops at the first one that fails.
- Mutants run on copies of your plugin, several at a time (`--workers`). Your files are never
  changed.
- Mutations on lines no test runs are not tried: they would all survive. They are counted under
  *Not tried*; [`--coverage`](writing-tests.md#coverage) shows those lines.

A mutant that makes a test run forever counts as caught, once it times out.

## Options

| Option | Default | Description |
| --- | --- | --- |
| `--workers=<n>` | `2` | Mutants run side by side, each on its own server |
| `--max=<n>` | `200` | Try at most this many mutants |
| `--min-score=<percent>` | | Exit with `1` when the score is lower, to keep it up in CI |
| `--filter=<text>` | | Only use the tests matching, as for [`golem run`](configuration.md#command-line) |

`--path`, `--tests`, `--pocketmine`, `--php` and `--phar` work as for `golem run`. The dashboard's
**Mutation** tab runs it too, and shows the survivors as they come.

```yaml
- run: vendor/bin/golem mutate --workers=4 --min-score=60
```
