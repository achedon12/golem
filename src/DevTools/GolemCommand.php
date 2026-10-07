<?php

declare(strict_types=1);

namespace Golem\DevTools;

use Golem\Golem;
use Golem\Runtime\GolemFactory;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\form\Form;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat;

final class GolemCommand extends Command implements PluginOwned
{
    private const PREFIX = TextFormat::GREEN . '[Golem] ' . TextFormat::RESET;

    private const USAGE = [
        '/golem spawn <name>' => 'spawn a simulated player',
        '/golem list' => 'list the golems',
        '/golem remove <name|all>' => 'disconnect golems',
        '/golem <name> chat <message>' => 'chat or run a command (start with /)',
        '/golem <name> walk <x> <z>' => 'walk a number of blocks',
        '/golem <name> come' => 'walk to you',
        '/golem <name> tp' => 'teleport to you',
        '/golem <name> jump|sneak|sprint' => 'move',
        '/golem <name> inbox [count]' => 'show the last messages it received',
        '/golem <name> form' => 'show the form it has open',
        '/golem <name> click <button>' => 'click a form button (label or number)',
    ];

    public function __construct(
        private readonly DevToolsPlugin $plugin,
        private readonly GolemFactory $golems,
    ) {
        parent::__construct('golem', 'Spawn and control simulated players', '/golem help');
        $this->setPermission('golem.command');
    }

    public function getOwningPlugin(): Plugin
    {
        return $this->plugin;
    }

    /**
     * @param list<string> $args
     */
    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return true;
        }

        $first = strtolower($args[0] ?? 'help');
        match ($first) {
            'help' => $this->help($sender),
            'spawn' => $this->spawn($sender, $args[1] ?? null),
            'list' => $this->list($sender),
            'remove' => $this->remove($sender, $args[1] ?? null),
            default => $this->control($sender, $args[0] ?? '', array_slice($args, 1)),
        };

        return true;
    }

    private function help(CommandSender $sender): void
    {
        $sender->sendMessage(self::PREFIX . 'Simulated players for testing:');
        foreach (self::USAGE as $usage => $description) {
            $sender->sendMessage(TextFormat::YELLOW . $usage . TextFormat::GRAY . ' - ' . $description);
        }
    }

    private function spawn(CommandSender $sender, ?string $name): void
    {
        if ($name === null) {
            $this->error($sender, 'Usage: /golem spawn <name>');

            return;
        }
        if (!Player::isValidUserName($name)) {
            $this->error($sender, "\"$name\" is not a valid player name");

            return;
        }
        // logging in with the name of an online player would kick that player
        if ($sender->getServer()->getPlayerExact($name) !== null) {
            $this->error($sender, "$name is already online");

            return;
        }
        // and with the name of a known player, the golem would get their op status and data
        if ($this->plugin->isRealPlayer($name)) {
            $this->error($sender, "$name is a real player of this server: pick another name for the golem");

            return;
        }

        $this->plugin->rememberGolem($name);
        $this->golems->spawn($name)->then(
            fn (Golem $golem) => $this->reply($sender, "{$golem->name()} joined the server"),
            fn (\Throwable $e) => $this->error($sender, "$name could not join: {$e->getMessage()}"),
        );
    }

    private function list(CommandSender $sender): void
    {
        $names = array_map(static fn (Golem $golem) => $golem->name(), $this->golems->online());
        $this->reply($sender, $names === [] ? 'No golems online' : count($names) . ' golem(s): ' . implode(', ', $names));
    }

    private function remove(CommandSender $sender, ?string $name): void
    {
        if ($name === null) {
            $this->error($sender, 'Usage: /golem remove <name|all>');

            return;
        }
        if (strtolower($name) === 'all') {
            $count = count($this->golems->online());
            $this->golems->despawnAll();
            $this->reply($sender, "Removed $count golem(s)");

            return;
        }
        $golem = $this->find($sender, $name);
        if ($golem !== null) {
            $golem->quit('Removed with /golem');
            $this->reply($sender, "Removed {$golem->name()}");
        }
    }

    /**
     * @param list<string> $args
     */
    private function control(CommandSender $sender, string $name, array $args): void
    {
        $golem = $this->find($sender, $name);
        if ($golem === null) {
            return;
        }

        $action = strtolower($args[0] ?? '');
        $rest = array_slice($args, 1);
        switch ($action) {
            case 'chat':
                $message = implode(' ', $rest);
                if ($message === '') {
                    $this->error($sender, "Usage: /golem {$golem->name()} chat <message>");

                    return;
                }
                $golem->chat($message);
                break;
            case 'walk':
                if (count($rest) !== 2 || !is_numeric($rest[0]) || !is_numeric($rest[1])) {
                    $this->error($sender, "Usage: /golem {$golem->name()} walk <x> <z>");

                    return;
                }
                $this->follow($sender, $golem, $golem->walk((float) $rest[0], (float) $rest[1]));
                break;
            case 'come':
            case 'tp':
                if (!$sender instanceof Player) {
                    $this->error($sender, 'Run this one in game');

                    return;
                }
                if ($action === 'tp') {
                    $golem->teleport($sender->getLocation());
                } else {
                    $this->follow($sender, $golem, $golem->walkTo($sender->getPosition()));
                }
                break;
            case 'jump':
                $golem->jump();
                break;
            case 'sneak':
                $golem->sneak(!$golem->player()->isSneaking());
                break;
            case 'sprint':
                $golem->sprint(!$golem->player()->isSprinting());
                break;
            case 'inbox':
                $count = max(1, (int) ($rest[0] ?? 10));
                $messages = array_slice($golem->messages(), -$count);
                $this->reply($sender, "Last messages of {$golem->name()}:");
                foreach ($messages === [] ? ['(nothing yet)'] : $messages as $message) {
                    $sender->sendMessage(TextFormat::GRAY . '  ' . $message);
                }

                return;
            case 'form':
                $this->showForm($sender, $golem);

                return;
            case 'click':
                $button = implode(' ', $rest);
                try {
                    $golem->clickButton(ctype_digit($button) ? (int) $button : $button);
                } catch (\LogicException $e) {
                    $this->error($sender, $e->getMessage());

                    return;
                }
                break;
            default:
                $this->error($sender, "Unknown action \"$action\". Try /golem help");

                return;
        }

        $this->reply($sender, "{$golem->name()}: $action done");
    }

    /**
     * @param \Golem\Runtime\Coroutine\Deferred<Golem> $walk
     */
    private function follow(CommandSender $sender, Golem $golem, \Golem\Runtime\Coroutine\Deferred $walk): void
    {
        $walk->then(
            fn () => $this->reply($sender, "{$golem->name()} arrived"),
            fn (\Throwable $e) => $this->error($sender, $e->getMessage()),
        );
    }

    private function showForm(CommandSender $sender, Golem $golem): void
    {
        if (!$golem->form() instanceof Form) {
            $this->reply($sender, "{$golem->name()} has no form open");

            return;
        }
        $data = $golem->formData();
        $title = is_string($data['title'] ?? null) ? TextFormat::clean($data['title']) : '';
        $this->reply($sender, "{$golem->name()} sees the form \"$title\"");
        foreach ((array) ($data['buttons'] ?? []) as $index => $button) {
            $text = is_array($button) && is_string($button['text'] ?? null) ? TextFormat::clean($button['text']) : '?';
            $sender->sendMessage(TextFormat::GRAY . "  [$index] $text");
        }
    }

    private function find(CommandSender $sender, string $name): ?Golem
    {
        foreach ($this->golems->online() as $golem) {
            if (strcasecmp($golem->name(), $name) === 0) {
                return $golem;
            }
        }
        $this->error($sender, "No golem named \"$name\". Spawn one with /golem spawn $name");

        return null;
    }

    private function reply(CommandSender $sender, string $message): void
    {
        if (!$sender instanceof Player || $sender->isConnected()) {
            $sender->sendMessage(self::PREFIX . $message);
        }
    }

    private function error(CommandSender $sender, string $message): void
    {
        if (!$sender instanceof Player || $sender->isConnected()) {
            $sender->sendMessage(self::PREFIX . TextFormat::RED . $message);
        }
    }
}
