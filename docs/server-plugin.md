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

Download the latest `Golem.phar` from [Poggit CI](https://poggit.pmmp.io/ci/achedon12/golem/Golem) and put it in your server's
`plugins/` folder. The `/golem` command is for operators (permission `golem.command`).

::: warning Development servers only
Golems are real players: they count towards the player limit and show up in the player list.
Keep the plugin off production servers.
:::

## Commands

| Command | What it does |
| --- | --- |
| `/golem spawn <name>` | spawns a golem (names of online players are refused, since that would kick them) |
| `/golem list` | lists the golems online |
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
