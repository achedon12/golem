---
description: "How Golem boots PocketMine-MP, loads plugins from source, drives generator tests across ticks and simulates players without a network."
---

# How it works

```
 vendor/bin/golem                       PocketMine-MP (official PHP build)
 ────────────────                       ──────────────────────────────────
 1. download & cache PHP + phar
 2. create a temp server folder  ───▶   boots, loads plugins/golem.php
    (fresh world, random port)          │
 3. start the server                    ├─ Golem loads your plugin from src/
                                        ├─ server enables it, world is ready
                                        ├─ first tick: discover tests/
 4. stream events.jsonl  ◀───────────── ├─ run each test, one JSON line per result
    print results live                  └─ shut down
 5. report: console, JUnit, GitHub annotations
```

## The CLI

`bin/golem` is plain PHP with no dependencies. It reads your `plugin.yml`, downloads the
`PocketMine-MP.phar` and PHP build from the official
[pmmp releases](https://github.com/pmmp/PHP-Binaries/releases) (once), writes a throwaway server
folder and starts PocketMine in it. It then follows a small event log the server writes, prints
results as they arrive, and stops the server if it crashes or hangs.

## Inside the server

The server folder contains a one-file script plugin, `plugins/golem.php`, that makes Golem's
classes autoloadable from where Golem is installed. Its main class:

1. loads the virions from your `.poggit.yml` under their own namespace, downloading them from
   Poggit when needed;
2. registers a folder plugin loader and loads your plugin straight from its source folder, so
   there is no phar to build and stack traces point at your files;
3. waits for the first server tick, when every plugin is enabled and the world is ready;
4. loads your tests and runs them one by one.

Tests run on the main thread, between ticks, like any plugin code. A test written as a generator
is a coroutine: when it yields, Golem registers a callback on what it is waiting for and returns
control to the server, which keeps ticking. The test resumes from a scheduler task when the value
is ready. A test that does not finish within its timeout is abandoned and reported as failed.

## Golems

A golem is a `Player` whose `NetworkSession` has no socket. Golem:

- injects the login result directly (the server is in offline mode, so there is no Xbox Live token
  to forge);
- answers each step of the spawn handshake the way a client would: accepting resource packs,
  requesting chunks, and confirming the spawn;
- records what the server sends instead of compressing and sending it, and reads it back through
  PocketMine's own hooks (chat, titles, forms) or as packet objects.

Because the player is real, everything else (events, permissions, inventories, damage, forms,
`Player::kick()`) is PocketMine's own code, not a reimplementation.

## Limitations

Golem is young. Things it does not do yet:

- **PocketMine-MP 5 only**, on Linux and macOS. Windows users can use WSL.
- **No movement simulation.** Golems teleport; they do not walk, jump, fall or swim through client
  movement packets, so anti-cheat and movement-based features cannot be tested yet.
- **The login is shortcut.** `PlayerPreLoginEvent` and the Xbox Live handshake are skipped:
  golems arrive with a login that was already accepted. `PlayerLoginEvent`, `PlayerJoinEvent` and
  everything after fire normally.
- **Tests run sequentially**, in a single server.

Ideas and pull requests on any of these are welcome.
