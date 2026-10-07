# Contributing to Golem

Thanks for taking the time! Bug reports, ideas, docs fixes and code are all welcome.

## Reporting a bug

Open an [issue](https://github.com/achedon12/golem/issues/new/choose) with the PocketMine-MP version,
your OS, the test that misbehaves and the output of `vendor/bin/golem --verbose`. A minimal plugin
reproducing the problem helps enormously.

## Working on the code

```bash
git clone https://github.com/achedon12/golem.git
cd golem
composer install --ignore-platform-reqs   # PocketMine's extensions only exist in its PHP build
composer test                             # runs the example plugin's tests with your checkout
```

The repository is organised as:

| Path | What lives there |
| --- | --- |
| `bin/golem` | CLI entry point |
| `src/Cli/` | everything that runs on your machine: options, downloads, server process, reports |
| `src/Runtime/` | everything that runs inside the server: plugin loader, test runner, fake network sessions |
| `src/` (root) | the public API used in tests: `TestCase`, `Golem`, assertions, attributes |
| `examples/hello-world/` | a small plugin and its tests, run by CI against every change |
| `docs/` | user documentation: the website (VitePress, `cd docs && npm run dev`) and the wiki are generated from it |

### Static analysis

PHPStan runs at level 8. It needs PocketMine's PHP build to know about extensions such as
`ext-encoding`; after one `composer test`, it is cached:

```bash
~/.cache/golem/php/8.4-Linux-x86_64/bin/php7/bin/php vendor/bin/phpstan analyse --memory-limit=1G
```

### Guidelines

- Keep the CLI dependency-free: it must run from `vendor/bin`, a clone or the GitHub Action alike.
- New golem actions or assertions come with a test in `examples/hello-world/tests` and a line in the docs.
- Match the existing style: strict types, final classes, small methods, comments that explain *why*.
- Add your change to the "Unreleased" section of `CHANGELOG.md`.

## Pull requests

Open the pull request against `main`. CI runs the example tests on PHP 8.1 and 8.4 plus PHPStan;
make sure both are green. Small, focused pull requests get reviewed fastest.
