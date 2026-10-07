---
description: "Golem CLI options, composer.json settings, environment variables and the throwaway PocketMine-MP test server it creates."
---

# Configuration

Golem works without any configuration when run from a plugin folder with a `tests/` folder.

## Command line

```
golem [run] [options]
golem init [--no-workflow]
```

| Option | Default | Description |
| --- | --- | --- |
| `--filter=<text>` | | Only run tests whose `Class::method` contains `<text>` (case-insensitive) |
| `--path=<dir>` | current folder | The plugin folder, containing `plugin.yml` and `src/` |
| `--tests=<dir>` | `tests` | The tests folder, relative to the plugin |
| `--pocketmine=<version>` | `latest` | The PocketMine-MP version to run, e.g. `5.44.3` |
| `--log-junit=<file>` | | Also write a JUnit XML report (needs `ext-dom`) |
| `--timeout=<seconds>` | `600` | Stop a run that takes longer than this |
| `--verbose` | | Print the server console while the tests run |
| `--keep` | | Keep the temporary server folder, to inspect its world or logs |
| `--php=<binary>` | downloaded | Use your own PocketMine PHP build |
| `--phar=<file>` | downloaded | Use your own `PocketMine-MP.phar`, e.g. a development build |
| `--ansi` / `--no-ansi` | auto | Force colours on or off. `NO_COLOR` is respected. |

Exit codes: `0` when every test passed (or was skipped), `1` when a test failed or the server
crashed, `2` for usage errors such as a missing `plugin.yml`.

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
- `pocketmine`: pin a PocketMine-MP version so every machine tests against the same one.
- `plugins`: other plugins to load alongside yours, typically the ones listed in your `depend`.
  `.phar` files and source folders (with `plugin.yml` and `src/`) both work.

## Environment variables

| Variable | Description |
| --- | --- |
| `GOLEM_CACHE_DIR` | Where PocketMine and its PHP build are cached. Defaults to `$XDG_CACHE_HOME/golem`, `~/.cache/golem`, or `~/Library/Caches/golem` on macOS. |
| `NO_COLOR` | Disables colours. |

## The test server

Each run gets a brand new server in a temporary folder, deleted afterwards (unless `--keep`):

- a superflat world named `golem`, survival mode, normal difficulty
- Xbox Live authentication off, so golems can log in
- a random free port on 127.0.0.1, so several runs can happen in parallel
- player data saving, auto-save, the auto-updater and crash reporting off
