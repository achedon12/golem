# HelloWorld, an example plugin tested with Golem

A deliberately small plugin that:

- greets players by name, shows them a title and gives newcomers three loaves of bread;
- reminds them about `/menu` two seconds after they join;
- lets operators `/heal` themselves;
- opens a menu form with `/menu` (teleport to spawn, set the time to day);
- stops players without the `hello.build` permission from breaking blocks.

Each behaviour is covered in [`tests/`](tests). Run them from the repository root:

```bash
php bin/golem --path=examples/hello-world
```

CI runs these tests on every change to Golem, so they double as Golem's own integration tests.
