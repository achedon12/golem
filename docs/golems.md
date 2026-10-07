---
description: "Golems are simulated PocketMine-MP players: make them chat, run commands, answer forms, break blocks, and read everything the server sent them."
---

# Golems

A golem is a simulated player. Spawn one from a test with `yield $this->golem('Name')` (the name
is optional: `Golem1`, `Golem2`… otherwise).

Under the hood it is a real `pocketmine\player\Player` connected through a network session that
has no network behind it. PocketMine runs the regular login, resource pack and spawn sequences,
so your plugin sees a normal player: join and quit events fire, it has an inventory, takes damage,
has permissions and can be kicked. When `yield $this->golem()` returns, the golem is in the world
and `PlayerJoinEvent` has already fired.

## Acting

| Method | What it does |
| --- | --- |
| `chat(string $message)` | Sends a chat message exactly like the chat box. Messages starting with `/` run as commands. |
| `command(string $line)` | Runs a command as the golem (leading `/` optional). Returns whether the command exists. |
| `op()` / `deop()` | Makes the golem a server operator, or not. Ops are revoked after each test. |
| `grant(string $permission, bool $value = true)` | Adds a permission attachment. `deny()` sets it to false. |
| `gamemode(GameMode $mode)` | Changes the game mode. |
| `teleport(Vector3 $target)` | Teleports, within the world or to a `Position` in another one. |
| `give(Item ...$items)` | Adds items to the inventory. |
| `hold(Item $item)` | Puts an item in the main hand. |
| `breakBlock(Vector3 $pos)` | Breaks a block with the same checks and events as survival mining. Returns `false` if it was cancelled or out of reach. |
| `interactBlock(Vector3 $pos, int $face = Facing::UP)` | Right-clicks a block face: places the held block or uses the held item on it. |
| `useItem()` | Uses the held item in the air (eat, throw, draw a bow…). |
| `attack(Entity\|Golem $target)` | Hits an entity or another golem with the held item. |
| `quit(string $reason = 'Golem left')` | Disconnects, as if the game was closed. |

## Moving

Golems move like players: one step per tick, stopped by walls, able to step up slabs and stairs,
falling off ledges and taking fall damage. `PlayerMoveEvent` fires along the way, so region,
pressure plate and anti-cheat logic all run.

| Method | What it does |
| --- | --- |
| `walkTo(Vector3 $target, float $speed = Golem::WALK_SPEED)` | Walks to a position (its height is ignored, golems follow the ground). Yield it to wait for the arrival. |
| `walk(float $x, float $z)` | Walks a number of blocks from where the golem stands. |
| `jump()` | Jumps, firing `PlayerJumpEvent`. Returns `false` when not on the ground. |
| `sneak(bool $sneaking = true)` / `sprint(bool $sprinting = true)` | Toggles sneaking or sprinting, firing the matching events. |

```php
$steve = yield $this->golem('Steve');

yield $steve->walk(25, 0);                 // ~6 seconds at walking speed
$this->assertReceivedMessage($steve, 'You entered the arena.');

yield $steve->walkTo($shop, Golem::SPRINT_SPEED);
```

A walk fails (and so does the test, unless it catches the exception) when the golem is stuck for a
second: a wall in the way, a hole too deep, or a plugin cancelling `PlayerMoveEvent`. Teleported
into the air, a golem falls.

Like any player, a golem cannot be hurt during its first 3 seconds (60 ticks) on the server. Tests
about damage should `yield $this->wait(60)` first.

## Facing and reach

Golems turn to face their target before `breakBlock()`, `interactBlock()` and `attack()`, because
PocketMine checks that players look at what they interact with. They still need to be within
reach: teleport them next to the target first.

Chat is rate-limited by PocketMine like for any player (a couple of messages per tick). If a test
sends many messages in a row, `yield $this->wait(1)` between them.

## Receiving

Everything the server sends to a golem is recorded:

| Method | Returns |
| --- | --- |
| `messages()` | chat messages, colour codes removed, translations resolved (`"Steve joined the game"`) |
| `rawMessages()` | chat messages with colour codes |
| `lastMessage()` | the latest chat message, or `null` |
| `titles()`, `subtitles()`, `actionBars()` | what was shown on screen |
| `tips()`, `popups()`, `toasts()` | the small texts above the hotbar, and toast notifications (`"title\nbody"`) |
| `sounds()` | sounds heard: named sounds (`random.levelup`) and sound events played in the world (`levelup`, `break`) |
| `packets(?string $class = null)` | every packet sent to the golem, including world broadcasts, optionally only one class, e.g. `packets(PlaySoundPacket::class)` |
| `disconnectReason()` | what the disconnection screen said after a kick, a ban or `quit()`; `null` while connected |
| `clearInbox()` | forgets everything received so far, to focus on what happens next |

Sounds, particles and animations played in the world (`World::addSound()`, `World::addParticle()`…)
are buffered by PocketMine and sent at the end of the tick. Let one tick pass before checking them:

```php
$steve->chat('/heal');

yield $this->wait(1);
$this->assertSoundPlayed($steve, 'levelup');
```

## Forms

Forms sent with `Player::sendForm()` work with any form library (FormAPI, pmforms, your own
`Form` implementation), because Golem reads them the way the client does.

| Method | What it does |
| --- | --- |
| `form()` | the most recent form waiting for an answer, as the `Form` object your plugin sent, or `null` |
| `formData()` | that form as the client receives it: `['title' => …, 'buttons' => […], …]` |
| `clickButton(string\|int $button)` | clicks a menu button by label (colour codes ignored) or index |
| `submitForm(mixed $data)` | answers with raw data: a button index, `true`/`false` for a modal, a list of values for a custom form |
| `closeForm()` | closes the form without answering, like the cross button |

```php
$steve->chat('/settings');
$this->assertFormOpen($steve, 'Settings');

$steve->submitForm([true, 'fr_FR', 12]);   // toggle, dropdown or input, slider

$this->assertReceivedMessage($steve, 'Settings saved');
```

## Everything else

`player()` returns the underlying `Player`, so the whole PocketMine API is available:

```php
$steve->player()->setHealth(4);
$steve->player()->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 200));
$this->assertTrue($steve->player()->isSprinting());
```
