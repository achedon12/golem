# Changelog

All notable changes to Golem are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). While Golem is 0.x, minor versions may contain
breaking changes; they will always be listed here.

## [Unreleased]

### Added

- `assertMatchesSnapshot()` and `--update-snapshots` (#43).
- `--coverage` lists the commands and event listeners of the plugin that the tests never reached
  (#45).
- `--compare=<version or fork>` runs the tests on two servers and reports what changes, grouped
  by cause, also in the GitHub Actions job summary (#44).

## [0.3.0] - 2026-10-08

### Added

- Golem is also a PocketMine-MP plugin: `/golem` spawns and controls golems on a development
  server (#33). `Golem.phar` is attached to every GitHub release, and built on every push to
  `main` (#37). Poggit was sunset along with PocketMine-MP, so it is not published there.
- Golems wear a visible stone-grey skin instead of a transparent one.
- `--pocketmine=owner/repository[@tag]` (and the action's `pocketmine` input) runs a PocketMine-MP
  fork that publishes releases like pmmp, now that PocketMine-MP itself has reached its end of
  support (#39).

### Fixed

- The GitHub Action keeps a separate cache for each `pocketmine` value and no longer restores
  another one's: a fork's code, which runs during the tests, could otherwise alter the cached PHP
  build used by other runs.
- The scoreboard reader understands newer protocols, where removals are flagged on each score
  entry instead of on the whole packet.
- Golems never save player data on the server (their `PlayerDataSaveEvent` is cancelled), so
  `/golem spawn` can refuse every name with saved data or op status, without exceptions.
- After a test, golems only lose the op status they did not have before spawning, so a name that
  is a real operator keeps its rights.

### Changed

- The plugin Golem injects into test servers is now named `GolemTestRunner`.

## [0.2.0] - 2026-10-07

### Added

- `assertSoundPlayed()`, `assertPacketSent()` and `Golem::sounds()` (#5).
- `#[FreshWorld]` and `$this->freshWorld()` to run a test in its own throwaway world (#8).
- `--watch` re-runs the tests whenever the plugin or its tests change (#24).
- Inventory menus: `window()`, `waitForWindow()`, `clickSlot()`, `closeWindow()`, `assertWindowOpen()`
  and `assertNoWindowOpen()`. Works with InvMenu (#23).
- Golems answer `NetworkStackLatencyPacket` pings and acknowledge re-sent containers like a real
  client, which InvMenu and some anti-cheats wait for.
- `#[World('path')]` and `$this->loadWorld()` to run a test in a copy of a world folder (#22).
- `Golem::interactEntity()`, `Golem::respawn()`, `assertDead()` and `assertAlive()` (#21).
- `Golem::scoreboard()`, `Golem::bossBar()`, `assertScoreboardContains()` and `assertBossBar()` (#20).
- `#[DataProvider]` to run a test once per data set (#19).
- `assertKicked()` and `Golem::disconnectReason()` (#18).
- Golems move: `walkTo()`, `walk()`, `jump()`, `sneak()` and `sprint()`, with collisions, step-up,
  gravity and fall damage (#6).
- Virions: the libraries listed in `.poggit.yml` are loaded (and downloaded from Poggit when
  needed), plus any listed in `extra.golem.virions` (#7).
- Documentation website at https://achedon12.github.io/golem/ and a wiki, both generated from `docs/`.

### Fixed

- `assertBlockAt()` loads the chunk it checks, which used to read as air when no golem was nearby.
- Virion downloads verify the TLS certificate (PocketMine's `Internet` helper does not), only
  follow HTTPS redirects, and are rejected unless they are virion phars.
- `.poggit.yml` is only looked up in the plugin folder and its parents inside the same git
  repository.
- Golems now receive packets broadcast by the world (sounds, particles, entity animations), which
  previously bypassed their inbox.

## [0.1.1] - 2026-10-07

### Changed

- The GitHub Action is now named "Golem PocketMine-MP Tests", a unique name for the GitHub Marketplace. `uses: achedon12/golem@v0` is unchanged.

## [0.1.0] - 2026-10-07

First public release.

### Added

- `golem` CLI: downloads and caches PocketMine-MP and its PHP build, boots a fresh server with the
  plugin loaded from source, runs the tests and streams the results.
- Golems: simulated players that log in through the real spawn sequence and can chat, run commands,
  answer forms, break and use blocks, use items, attack and quit.
- Everything sent to a golem is recorded: chat, titles, action bars, tips, popups, toasts, forms
  and raw packets.
- Generator-based tests with `wait()`, `waitUntil()` and support for yielding PocketMine promises.
- General and Minecraft-specific assertions, with expected/actual values and the failing line in
  reports.
- `#[Test]`, `#[Timeout]` and `#[Skip]` attributes, `setUp()` (which may be a generator) and
  `tearDown()`.
- JUnit XML reports and GitHub Actions annotations.
- `golem init` to scaffold a first test and a workflow.
- A composite GitHub Action, `achedon12/golem@v0`.

[0.3.0]: https://github.com/achedon12/golem/releases/tag/v0.3.0
[0.2.0]: https://github.com/achedon12/golem/releases/tag/v0.2.0
[0.1.1]: https://github.com/achedon12/golem/releases/tag/v0.1.1
[0.1.0]: https://github.com/achedon12/golem/releases/tag/v0.1.0
