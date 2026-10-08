---
description: "Golem as a PocketMine-MP plugin: spawn simulated players on a development server with /golem and test multiplayer plugins alone."
---

# Server plugin

Golem is also a regular plugin for your **development server**. Drop it in `plugins/` and spawn
simulated players with `/golem`: test a minigame, a PvP arena or a queue alone, without starting
three Minecraft accounts.

The golems are the same as in [tests](golems.md): real players that join, chat, walk and answer
forms, so every plugin on the server reacts to them as usual.

## Install

Download [`Golem.phar`](https://github.com/achedon12/golem/releases/latest/download/Golem.phar) from the [latest release](https://github.com/achedon12/golem/releases/latest)
and put it in your server's `plugins/` folder. The `/golem` command is for operators (permission `golem.command`).

::: warning Development servers only
Golems are real players: they count towards the player limit and show up in the player list.
Keep the plugin off production servers.
:::

## Commands

| Command | What it does |
| --- | --- |
| `/golem spawn <name>` | spawns a golem. Names of real players are refused: an online one would be kicked, and an op or a player with saved data would lend the golem their rights and data |
| `/golem spawn <name> <count>` | spawns a crowd, `<name>1` to `<name><count>` (at most 100), one every other tick: see [Benchmark](benchmark.md#on-a-development-server) |
| `/golem list` | lists the golems online |
| `/golem record [player...]` | records what players do (you, by default), to replay it in a test: see [Recording a test](#recording-a-test) |
| `/golem record stop [TestName]` | stops recording and writes the test |
| `/golem remove <name\|all>` | disconnects golems |
| `/golem <name> chat <message>` | chats as the golem; messages starting with `/` run as commands |
| `/golem <name> walk <x> <z>` | walks a number of blocks |
| `/golem <name> come` | walks to you |
| `/golem <name> tp` | teleports to you |
| `/golem <name> jump` / `sneak` / `sprint` | moves; `sneak` and `sprint` toggle |
| `/golem <name> inbox [count]` | shows the last messages the golem received |
| `/golem <name> form` | shows the form the golem has open, with its buttons |
| `/golem <name> click <button>` | clicks a form button, by label or number |

Golems are removed when the plugin is disabled or the server stops.

## Example: a two-player duel, alone

```text
/golem spawn Rival
/golem Rival tp
/golem Rival chat /duel accept
/golem Rival inbox
```

You just accepted your own duel request and saw what your opponent was told. Give golems
permissions with `/op` or your permission plugin, as for any player.

## Recording a test

Play a scenario once by hand, and get a test that replays it:

```text
/golem record
… open the menu, pick a kit, walk to the arena, break a block …
/golem record stop kit menu
[Golem] Recorded 12 action(s) to plugin_data/Golem/recordings/KitMenuTest.php
```

```php
final class KitMenuTest extends TestCase
{
    #[Timeout(412)]
    public function testRecordedSession(): \Generator
    {
        [$steve] = yield $this->golems(['Steve']);
        $steve->gamemode(GameMode::SURVIVAL);
        $steve->teleport(new Vector3(256, 4, 256));

        $steve->command('kits');
        yield $this->wait(23);
        $steve->clickSlot(2);
        yield $this->wait(40);
        $steve->walkTo(new Vector3(258.2, 4, 256));
        // …

        // TODO: assert what should have happened
    }
}
```

Golem records chat, commands, form answers, window clicks and closes, blocks broken and
right-clicked, attacks and right-clicks on other recorded players, the held slot, jumping,
sneaking, sprinting, respawning and leaving. Movement becomes waypoints every 2 blocks, and the
pauses between actions are kept (shortened to 5 seconds at most).

Record several players at once with `/golem record Steve Alex`: they become golems of the same
test, and what they do to each other is replayed too.

The test starts from the players' position, game mode and op status, not from their inventory
or the state of the world: set those up in the test if the scenario needs them. Move the file to
your tests folder, give it your tests' namespace, then replace the `TODO` with assertions of what
should happen.
