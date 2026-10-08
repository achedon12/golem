<?php

declare(strict_types=1);

namespace Example\HelloWorld;

use pocketmine\form\Form;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

final class MenuForm implements Form
{
    private const BUTTONS = ['Spawn', 'Daytime'];

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'form',
            'title' => TextFormat::BOLD . 'Server menu',
            'content' => 'Where to?',
            'buttons' => array_map(static fn (string $text) => ['text' => $text], self::BUTTONS),
        ];
    }

    public function handleResponse(Player $player, $data): void
    {
        // a modified client can answer anything: closing the form (null), or not a button number
        if (!is_int($data)) {
            return;
        }
        switch (self::BUTTONS[$data] ?? null) {
            case 'Spawn':
                $player->teleport($player->getWorld()->getSpawnLocation());
                $player->sendMessage('Teleported to spawn.');
                break;
            case 'Daytime':
                $player->getWorld()->setTime(1000);
                $player->sendMessage('Good morning!');
                break;
        }
    }
}
