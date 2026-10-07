# Continuous integration

## GitHub Actions

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]

jobs:
  golem:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: achedon12/golem@v0
```

That is the whole setup: the runner's PHP runs the CLI, and PocketMine plus its PHP build are
cached between runs. When a test fails, the job fails and the failure is annotated on the pull
request diff, at the line of the test.

### Inputs

| Input | Default | Description |
| --- | --- | --- |
| `path` | `.` | Plugin folder, for repositories holding several plugins |
| `tests` | from `composer.json`, else `tests` | Tests folder, relative to the plugin |
| `pocketmine` | from `composer.json`, else latest | PocketMine-MP version |
| `filter` | | Only run matching tests |
| `junit` | `golem-junit.xml` | JUnit report path, empty to skip it |

### Testing against several PocketMine versions

```yaml
jobs:
  golem:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        pocketmine: ['5.40.0', '5.44.3']
    steps:
      - uses: actions/checkout@v4
      - uses: achedon12/golem@v0
        with:
          pocketmine: ${{ matrix.pocketmine }}
```

### Keeping the JUnit report

```yaml
      - uses: achedon12/golem@v0
      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: golem-report
          path: golem-junit.xml
```

## Other CI systems

Any Linux machine with PHP 8.1+ can run Golem:

```bash
composer install
vendor/bin/golem --log-junit=build/golem-junit.xml
```

Cache `~/.cache/golem` between runs to skip the downloads (or point `GOLEM_CACHE_DIR` at a cached
folder). GitLab CI, for example:

```yaml
golem:
  image: php:8.3-cli
  variables:
    GOLEM_CACHE_DIR: "$CI_PROJECT_DIR/.golem-cache"
  cache:
    paths: [.golem-cache/]
  script:
    - apt-get update && apt-get install -y git unzip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install
    - vendor/bin/golem --log-junit=golem-junit.xml
  artifacts:
    when: always
    reports:
      junit: golem-junit.xml
```
