---
description: "Golem CLI options, composer.json settings, environment variables and the throwaway PocketMine-MP test server it creates."
---

# Configuration

Golem works without any configuration when run from a plugin folder with a `tests/` folder.

## Command line

```
golem [run] [options]
golem init [--no-workflow]
golem fuzz [--duration=60] [--golems=3] [--seed=<n>] [--write-tests]
golem ui [--port=<port>] [--no-open]
golem mutate [--workers=2] [--max=200] [--min-score=<percent>]
golem compat <plugin...>
golem bench [--players=20] [--duration=60] [--min-tps=<tps>] [--save-baseline=<file>] [--baseline=<file>]
```

| Option | Default | Description |
| --- | --- | --- |
| `--filter=<text>` | | Only run tests whose `Class::method` contains `<text>` (case-insensitive). Several, separated by `\|`, run the tests matching any of them: `--filter='KitMenuTest\|HealCommandTest::testRegular'` |
| `--path=<dir>` | current folder | The plugin folder, containing `plugin.yml` and `src/` |
| `--tests=<dir>` | `tests` | The tests folder, relative to the plugin |
| `--pocketmine=<version>` | `latest` | The PocketMine-MP version to run, e.g. `5.44.3`, or a fork: `owner/repository` (its latest release) or `owner/repository@tag` (see [Forks](#pocketmine-mp-forks)) |
| `--compare=<version>` | | Run the tests on the configured version, then on this one (a version or a fork), and report what changes (see [Forks](#pocketmine-mp-forks)) |
| `--coverage` | | After the run, list the plugin's commands the tests never ran, listeners they never called and lines of `src/` that never ran (see [Coverage](writing-tests.md#coverage)) |
| `--coverage-clover=<file>` | | Also write line coverage as a Clover XML report (implies `--coverage`) |
| `--repeat=<n>` | `1` | Run every test `n` times; tests that pass in some runs and fail in others are listed as flaky |
| `--random-order[=<seed>]` | | Shuffle the order of the tests (a new order on every repetition), to find tests that depend on the ones before them. The seed is printed: pass it again to get the same order |
| `--stop-on-failure` | | Stop at the first test that fails or errors |
| `--parallel=<n>` | `1` | Split the test files between `n` servers running side by side. Each class runs on one server, so tests of a class still share it; tests of different classes must not depend on each other |
| `--with=<plugin,...>` | | Load other plugins (phars or folders) next to yours for this run (see [Compatibility](compatibility.md)) |
| `--log-junit=<file>` | | Also write a JUnit XML report (needs `ext-dom`) |
| `--report-html=<file>` | | Also write a self-contained HTML report: results, failures with their code, coverage |
| `--report-markdown=<file>` | | Also write a Markdown summary, as the action's pull request comment |
| `--log-events=<file>` | | Also write the run as JSON lines, one per event (started, each test, finished with the summary and coverage), for tools that follow it |
| `--teamcity` | | Report with [TeamCity service messages](ci.md#teamcity) instead of the usual output |
| `--timeout=<seconds>` | `600` | Stop a run that takes longer than this |
| `--update-snapshots` | | Rewrite the snapshots of `assertMatchesSnapshot()` instead of comparing them |
| `--watch` | | Keep running: re-run the tests whenever `src/`, `tests/`, `resources/` or `plugin.yml` change |
| `--verbose` | | Print the server console while the tests run |
| `--keep` | | Keep the temporary server folder, to inspect its world or logs |
| `--php=<binary>` | downloaded | Use your own PocketMine PHP build |
| `--phar=<file>` | downloaded | Use your own `PocketMine-MP.phar`, e.g. a development build |
| `--ansi` / `--no-ansi` | auto | Force colours on or off. `NO_COLOR` is respected. |

Exit codes: `0` when every test passed (or was skipped), `1` when a test failed or the server
crashed, `2` for usage errors such as a missing `plugin.yml`.

`golem fuzz` and `golem bench` take `--path`, `--pocketmine`, `--php`, `--phar` and `--verbose`,
plus their own options: see [Fuzzing](fuzzing.md) and [Benchmark](benchmark.md).

## composer.json

Project-wide defaults go in `extra.golem`. Command line options win over them.

```json
{
    "extra": {
        "golem": {
            "tests": "tests/golem",
            "pocketmine": "5.44.3",
            "plugins": [
                "dependencies/EconomyAPI.phar",
                "../MyOtherPlugin"
            ]
        }
    }
}
```

- `tests`: the tests folder.
- `pocketmine`: pin a PocketMine-MP version so every machine tests against the same one, or a
  fork as `owner/repository@tag`.
- `plugins`: other plugins to load alongside yours, typically the ones listed in your `depend`.
  `.phar` files and source folders (with `plugin.yml` and `src/`) both work.
- `virions`: virions to load that are not in your `.poggit.yml` (folders with `virion.yml` and `src/`,
  or virion phars).

## Virions

Golem reads your plugin's `.poggit.yml` (in its folder, or a parent folder for repositories with
several plugins) and loads the virions it lists before your plugin:

```yaml
projects:
  MyPlugin:
    libs:
      - src: muqsit/InvMenu/InvMenu    # downloaded from Poggit, cached for a day
        version: ^4.6.0
      - src: libs/MyLocalVirion        # a virion folder or phar in the repository
        vendor: raw
```

Poggit has been read-only since it was sunset in September 2026, along with PocketMine-MP. Its
downloads still work, and Golem keeps a cached copy of every virion, but if Poggit goes offline,
point `extra.golem.virions` (or `vendor: raw` entries) at virion folders or phars you keep yourself.

Your source code uses each virion's own namespace (its `antigen`), and that is the namespace Golem
registers: the shading Poggit applies when building the phar is not needed when running from
source. Nothing to configure if your plugin already builds on Poggit.

## PocketMine-MP forks

PocketMine-MP reached its end of support in July 2026: 5.44.3 is the last release of
`pmmp/PocketMine-MP`, and new Minecraft versions are followed by forks. Golem runs any fork that
publishes its GitHub releases like pmmp did, with a `PocketMine-MP.phar` asset:

```bash
vendor/bin/golem --pocketmine=Plutonium-Mcpe/PocketMine-MP           # its latest release
vendor/bin/golem --pocketmine=Plutonium-Mcpe/PocketMine-MP@5.118.8   # a given release
```

Testing on the fork your server will run is worth it: forks follow newer protocols, where packet
classes change shape (`PlaySoundPacket::create()` takes another argument, `SetScorePacket` lost its
`TYPE_CHANGE` constant), and they sometimes change gameplay too. Golem's own example plugin passes
on pmmp 5.44.3 but not on Plutonium 5.118.8, for exactly those reasons, and a test caught that
Plutonium computes fall damage differently (a 10 block fall costs 6 health instead of 7).

### Migration report

`--compare` answers *what breaks if I move to that fork?* in one command: it runs your tests on
the configured version (latest pmmp by default), then on the fork, and lists the tests whose
outcome changed, grouped by cause:

```text
$ vendor/bin/golem --compare=Plutonium-Mcpe/PocketMine-MP

  Migration report 5.44.3 → 5.118.8 (Plutonium-Mcpe/PocketMine-MP)

  ✗ passed → failed  1 test
    on 5.118.8 (Plutonium-Mcpe/PocketMine-MP): a 10 block fall costs 7 health
    expected 13 actual 14.0
    · MovementTest › falling hurts

  Tests: 23 behave the same, 1 break, 0 get fixed, 0 other changes
```

It exits with 1 when a test that passes on the first server breaks on the second, and on GitHub
Actions it also writes the table to the job summary. Tests broken by the same error (a packet
whose signature changed, say) are shown once, with the list of affected tests.

Golem itself is checked against Plutonium in its CI. A fork that rewrites PocketMine's network
internals may need Golem to adapt: please open an issue.

For a fork without releases, build its phar yourself and pass it with `--phar=`.

## Environment variables

| Variable | Description |
| --- | --- |
| `GOLEM_CACHE_DIR` | Where PocketMine and its PHP build are cached. Defaults to `$XDG_CACHE_HOME/golem`, `~/.cache/golem`, or `~/Library/Caches/golem` on macOS. |
| `NO_COLOR` | Disables colours. |

## The test server

Each run gets a brand new server in a temporary folder, deleted afterwards (unless `--keep`):

- a superflat world named `golem`, survival mode, normal difficulty, shared by the tests (tests marked
  `#[FreshWorld]` get their own)
- Xbox Live authentication off, so golems can log in
- a random free port on 127.0.0.1, so several runs can happen in parallel
- player data saving, auto-save, the auto-updater and crash reporting off
