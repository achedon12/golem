<?php

declare(strict_types=1);

namespace Golem\Runtime;

use pocketmine\command\Command;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\server\CommandEvent;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginOwned;
use pocketmine\timings\TimingsHandler;

/**
 * Counts how often the tests ran each command and called each event listener of the
 * plugin under test. No code coverage tool involved: commands are counted from
 * CommandEvent, and each listener is swapped for one that counts, then calls it.
 *
 * @internal
 */
final class Coverage
{
    /** @var array<string, int> runs by command name */
    private array $commands = [];

    /** @var array<string, int> calls by listener ("Class::method (Event)") */
    private array $listeners = [];

    public function __construct(
        private readonly PluginBase $golem,
        private readonly Plugin $subject,
    ) {
    }

    public function install(): void
    {
        $this->watchCommands();
        $this->wrapListeners();
    }

    /**
     * @return array{commands: array<string, int>, listeners: array<string, int>}
     */
    public function report(): array
    {
        ksort($this->commands);
        ksort($this->listeners);

        return ['commands' => $this->commands, 'listeners' => $this->listeners];
    }

    private function watchCommands(): void
    {
        $map = $this->golem->getServer()->getCommandMap();
        foreach ($map->getCommands() as $command) {
            if ($this->owns($command)) {
                $this->commands[$command->getName()] = 0;
            }
        }

        $this->golem->getServer()->getPluginManager()->registerEvent(CommandEvent::class, function (CommandEvent $event) use ($map): void {
            $label = strtolower(explode(' ', trim($event->getCommand()), 2)[0]);
            $command = $map->getCommand($label);
            if ($command !== null && isset($this->commands[$command->getName()])) {
                $this->commands[$command->getName()]++;
            }
        }, EventPriority::MONITOR, $this->golem);
    }

    private function wrapListeners(): void
    {
        foreach (HandlerListManager::global()->getAll() as $eventClass => $list) {
            foreach (EventPriority::ALL as $priority) {
                foreach ($list->getListenersByPriority($priority) as $listener) {
                    if ($listener->getPlugin() !== $this->subject) {
                        continue;
                    }
                    $label = self::label($listener, $eventClass);
                    $this->listeners[$label] = 0;
                    $handler = $listener->getHandler();
                    $list->unregister($listener);
                    $list->register(new RegisteredListener(
                        function (Event $event) use ($handler, $label): void {
                            $this->listeners[$label]++;
                            $handler($event);
                        },
                        $listener->getPriority(),
                        $this->subject,
                        $listener->isHandlingCancelled(),
                        new TimingsHandler('Golem coverage: ' . $label),
                    ));
                }
            }
        }
    }

    private function owns(Command $command): bool
    {
        return $command instanceof PluginOwned && $command->getOwningPlugin() === $this->subject;
    }

    /**
     * @param RegisteredListener<Event> $listener
     */
    private static function label(RegisteredListener $listener, string $eventClass): string
    {
        $function = new \ReflectionFunction($listener->getHandler());
        $class = $function->getClosureScopeClass()?->getShortName() ?? '?';
        $event = substr($eventClass, (int) strrpos('\\' . $eventClass, '\\'));

        return "$class::{$function->getName()} ($event)";
    }
}
