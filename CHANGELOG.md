# Changelog

All notable changes to Golem are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). While Golem is 0.x, minor versions may contain
breaking changes; they will always be listed here.

## [Unreleased]

### Added

- `golem ui` opens a local dashboard to run the tests and follow them live, with failures and
  coverage shown in the code (#70, #71).
- `--log-events=<file>` writes a run as JSON lines (#71).
- The dashboard's scenario editor builds a test from golems, actions and checks, writes it and runs
  it (#73).
- The dashboard keeps the last runs and pinned settings in the browser, to run them again in a
  click, and draws the benchmarks over time (#74).
- `$this->item('diamond_sword', 3)` in tests gets an item by name.
- The dashboard runs `golem fuzz` and `golem bench`, with the benchmark drawn as a chart (#72).

## [0.4.0] - 2026-10-08

### Added

Writing tests:

- `assertMatchesSnapshot()` and `--update-snapshots` (#43).
- `golems(20)` or `golems(['Steve', 'Alex'])` spawns several golems that join together (#53).
- `assertTpsAbove()` checks the server's TPS in a test (#47).

Running them:

- `--parallel=<n>` (and the action's `parallel` input) splits the test files between several
  servers running side by side: the example suite runs in 20 s instead of 43 s on 4 servers (#56).
- `--compare=<version or fork>` runs the tests on two servers and reports what changes, grouped
  by cause, also in the GitHub Actions job summary (#44).
- `--coverage` lists the commands, event listeners and lines of `src/` the tests never reached
  (#45). Lines need pcov or Xdebug: Golem turns on the Xdebug build that ships with PocketMine's
  PHP. `--coverage-clover=<file>` writes them for Codecov (#58).
- `--teamcity` reports with TeamCity service messages (#57).

Finding bugs and slowdowns:

- `golem fuzz` has golems do random things to the plugin and reports every exception, with the
  actions that led to it and a seed to replay them (#46); `--write-tests` turns each crash into a
  test that replays it (#54).
- `golem bench` brings golems in a few at a time and reports TPS, tick usage and memory as players
  are added, and the plugin's slowest listeners and tasks; `--min-tps` fails the run below a
  threshold (#47). `--save-baseline` and `--baseline` compare with an earlier run and fail on a
  performance regression, also in the GitHub Actions job summary (#55).

On a development server:

- `/golem spawn <name> <count>` spawns a crowd (#47).
- `/golem record` records what players do and writes a Golem test that replays it (#59).

### Changed

- `v0`, which the GitHub Action is used with (`achedon12/golem@v0`), is now a branch instead of a
  tag: Packagist published the tag as a version and refused to let it move. `@v0` works as before.

### Fixed

- The example plugin's menu form no longer throws on an invalid answer, found by `golem fuzz`.

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

[0.4.0]: https://github.com/achedon12/golem/releases/tag/v0.4.0
[0.3.0]: https://github.com/achedon12/golem/releases/tag/v0.3.0
[0.2.0]: https://github.com/achedon12/golem/releases/tag/v0.2.0
[0.1.1]: https://github.com/achedon12/golem/releases/tag/v0.1.1
[0.1.0]: https://github.com/achedon12/golem/releases/tag/v0.1.0
