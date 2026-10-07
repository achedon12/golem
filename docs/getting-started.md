---
description: "Install Golem, scaffold a first test and run integration tests for your PocketMine-MP plugin in about five minutes."
---

# Getting started

This guide takes a plugin with no tests to a green test run in about five minutes.

## Requirements

- Linux or macOS (on Windows, run Golem from WSL)
- PHP 8.1 or newer, to run the `golem` command
- A plugin laid out the usual way: `plugin.yml` and `src/` in the same folder

You do not need to install PocketMine-MP or its PHP build: Golem downloads both from the official
pmmp releases the first time it runs and caches them in `~/.cache/golem` (`~/Library/Caches/golem`
on macOS). Set `GOLEM_CACHE_DIR` to put them somewhere else.

## 1. Install

From your plugin folder:

```bash
composer require --dev achedon12/golem
```

No `composer.json` yet? `composer init` creates one in a few questions. Composer is only used to
give your IDE autocompletion for Golem's classes: the CLI itself has no dependencies.

## 2. Create a first test

```bash
vendor/bin/golem init
```

This creates two files:

- `tests/ExampleTest.php`, a test that checks your plugin enables and that a player can join
- `.github/workflows/golem.yml`, which runs your tests on every push (skip it with `--no-workflow`)

## 3. Run it

```bash
vendor/bin/golem
```

The first run downloads PocketMine and its PHP build (a few dozen MB, once). After that, a run takes a couple of
seconds to boot the server plus well under a second per test.

## 4. Write a real test

Say your plugin gives new players a compass when they join. Replace the example with:

```php
<?php

declare(strict_types=1);

namespace MyName\MyPlugin\Tests;

use Generator;
use Golem\TestCase;
use pocketmine\item\VanillaItems;

final class JoinTest extends TestCase
{
    public function testNewPlayersGetACompass(): Generator
    {
        $steve = yield $this->golem('Steve');

        $this->assertHasItem($steve, VanillaItems::COMPASS());
    }
}
```

`yield $this->golem('Steve')` spawns a simulated player and waits until it is fully in the world,
after every join event has fired. From there, read [Writing tests](writing-tests.md) and
[Golems](golems.md).

## Tips

- Run a single test with `vendor/bin/golem --filter=JoinTest` (or any part of `Class::method`).
- Add `--verbose` to see the server console while the tests run.
- Add `"scripts": { "test": "golem" }` to your `composer.json` and run `composer test`.
