# Changelog

All notable changes to Golem are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/). While Golem is 0.x, minor versions may contain
breaking changes; they will always be listed here.

## [Unreleased]

### Added

- `assertSoundPlayed()`, `assertPacketSent()` and `Golem::sounds()` (#5).
- `#[FreshWorld]` and `$this->freshWorld()` to run a test in its own throwaway world (#8).
- `#[DataProvider]` to run a test once per data set (#19).
- `assertKicked()` and `Golem::disconnectReason()` (#18).
- Golems move: `walkTo()`, `walk()`, `jump()`, `sneak()` and `sprint()`, with collisions, step-up,
  gravity and fall damage (#6).
- Virions: the libraries listed in `.poggit.yml` are loaded (and downloaded from Poggit when
  needed), plus any listed in `extra.golem.virions` (#7).
- Documentation website at https://achedon12.github.io/golem/ and a wiki, both generated from `docs/`.

### Fixed

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

[0.1.1]: https://github.com/achedon12/golem/releases/tag/v0.1.1
[0.1.0]: https://github.com/achedon12/golem/releases/tag/v0.1.0
