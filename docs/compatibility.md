---
description: "golem compat: run the tests of a PocketMine-MP plugin next to other plugins and see what breaks: failing tests, commands taken by another plugin."
---

# Compatibility

Your plugin rarely runs alone. Another plugin can take one of your commands, cancel the events you
listen to, or change what players see. `golem compat` runs your tests alone, then again with other
plugins loaded next to yours, and shows what changes:

```bash
vendor/bin/golem compat EssentialsMP libs/MyEconomy.phar ../my-other-plugin
```

```text
  Migration report alone → with HealPlus

  ✗ passed → failed  1 test
    · HealCommandTest › operators can heal themselves
    expected 20.0 actual 4.0

  Tests: 1 behave the same, 1 break, 0 get fixed, 0 other changes

  ! /heal of HelloWorld is taken by HealPlus: typing /heal runs HealPlus's, the other is only /helloworld:heal

  HelloWorld does not work the same with HealPlus.
```

The other plugins can be phars, plugin folders, or names of plugins of the Poggit archive,
downloaded once into Golem's cache. A plugin that cannot load (an API version it does not
support, a missing dependency) is reported with the reason the server gave.

The exit code is `1` when a test breaks or a new command conflict appears, so it can run in CI to
keep working with the plugins your users install alongside yours.

::: warning
Plugins downloaded from Poggit run on your machine like any plugin you install: only name plugins
you trust.
:::

## Command conflicts

When two plugins register the same command, the first one keeps it and the other is only
reachable as `/plugin:command`. Golem checks this on every run, not only with `golem compat`:

```text
  HelloWorld 1.0.0 · PocketMine-MP 5.44.3 · 35 tests
  ! /heal of HelloWorld is taken by HealPlus: typing /heal runs HealPlus's, the other is only /helloworld:heal
```

## Loading other plugins in a run

`--with` loads other plugins for a single `golem run`, for example a dependency you test against:

```bash
vendor/bin/golem --with=libs/MyEconomy.phar,../my-other-plugin
```

Plugins your tests always need belong in [`extra.golem.plugins`](configuration.md#composer-json)
instead.
