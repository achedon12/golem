---
layout: home
title: Golem
titleTemplate: Integration tests for PocketMine-MP plugins

hero:
  name: golem
  text: Test your plugin the way players use it
  tagline: One command boots a real PocketMine-MP server, loads your plugin from source and fills it with simulated players. No mocks, no client, no more testing by hand.
  image:
    src: /logo.svg
    alt: The Golem pixel-art head
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: Write your first test
      link: /writing-tests
    - theme: alt
      text: View on GitHub
      link: https://github.com/achedon12/golem

features:
  - icon: 🖥️
    title: A real server
    details: Your plugin runs on the PocketMine-MP version you pick, with every event fired in the real order. If it passes in Golem, it works in production.
  - icon: 🧍
    title: Simulated players
    details: Golems are genuine Player objects behind a fake network session. They join, chat, run commands, click forms, break blocks and get kicked.
    link: /golems
    linkText: What golems can do
  - icon: ⏱️
    title: Time is a first-class citizen
    details: Tests are generators. Yield a golem, a number of ticks or a condition, and the test resumes when it is ready. Cooldowns and delayed tasks become testable.
    link: /writing-tests#waiting-tests-as-generators
    linkText: Waiting in tests
  - icon: 📦
    title: Zero setup
    details: Golem downloads the official PocketMine PHP build and server phar once, caches them, and creates a fresh world for every run.
  - icon: 🔎
    title: Failures you can read
    details: Expected versus actual, the failing line of your test, and the server log when something crashes. Minecraft-aware assertions included.
    link: /assertions
    linkText: All assertions
  - icon: ✅
    title: Made for CI
    details: JUnit reports, failures annotated on the pull request, and a GitHub Action that fits in one line.
    link: /ci
    linkText: Set up CI
---

<section class="home-section">
<div class="home-split">
<div>

## Twenty seconds of Golem

<p class="lead">A test spawns Steve, he joins a real server, gets greeted, and the test checks it. Then the same thing for every behaviour of your plugin, in one command.</p>

```php
public function testGreetsPlayersByName(): Generator
{
    $steve = yield $this->golem('Steve');

    $this->assertReceivedMessage($steve, 'Welcome, Steve!');
    $this->assertHasItem($steve, VanillaItems::BREAD(), 3);
}
```

</div>
<div>

<video class="home-media home-video" controls preload="none" playsinline poster="/intro-poster.jpg">
  <source src="/golem-intro.mp4" type="video/mp4">
</video>

</div>
</div>
</section>

<section class="home-section">

## Three steps

<p class="lead">From a plugin with no tests to a green run in about five minutes.</p>

<div class="home-steps">
<div class="home-step">

### Install

<p>Golem is a dev dependency. The CLI itself needs nothing else.</p>

```bash
composer require --dev achedon12/golem
```

</div>
<div class="home-step">

### Scaffold

<p>A first test and a GitHub Actions workflow.</p>

```bash
vendor/bin/golem init
```

</div>
<div class="home-step">

### Run

<p>Boots a server, runs every test, stops it.</p>

```bash
vendor/bin/golem
```

</div>
</div>
</section>

<section class="home-section">
<div class="home-split even">
<div>

## Results as they happen

<p class="lead">Each test reports as soon as it finishes, with how long it took in milliseconds and server ticks. This is the real output of the example plugin shipped with Golem.</p>

<img class="home-media" src="/terminal.svg" alt="Golem output: every test of the example plugin passing">

</div>
<div>

## And when it breaks

<p class="lead">You see what was expected, what the server actually sent, and the exact line of your test.</p>

<img class="home-media" src="/terminal-failure.svg" alt="A failing test with expected and actual values and the failing line">

</div>
</div>
</section>

<section class="home-section">
<div class="home-split">
<div>

## Every pull request, tested

<p class="lead">Add one step to your workflow. PocketMine is cached between runs, a JUnit report is written, and failures are annotated right on the diff.</p>

</div>
<div>

```yaml
jobs:
  golem:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: achedon12/golem@v0
```

</div>
</div>
</section>

<section class="home-section home-cta">

## Stop testing your plugin by hand

<p class="lead">Golem is open source (MIT) and young: feedback shapes what comes next.</p>

<div class="VPHero" style="padding:0"><div class="actions" style="justify-content:center">
<div class="action"><a class="VPButton medium brand" href="/golem/getting-started">Get started</a></div>
<div class="action"><a class="VPButton medium alt" href="https://github.com/achedon12/golem/discussions">Join the discussion</a></div>
</div></div>

</section>
