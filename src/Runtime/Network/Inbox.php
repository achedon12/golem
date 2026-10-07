<?php

declare(strict_types=1);

namespace Golem\Runtime\Network;

use pocketmine\form\Form;
use pocketmine\network\mcpe\protocol\ClientboundPacket;

/**
 * Everything the server sent to a golem, in order.
 */
final class Inbox
{
    public const CHAT = 'chat';
    public const POPUP = 'popup';
    public const TIP = 'tip';
    public const TITLE = 'title';
    public const SUBTITLE = 'subtitle';
    public const ACTION_BAR = 'action_bar';
    public const TOAST = 'toast';

    /** @var array<self::*, list<string>> */
    private array $texts = [];

    /** @var array<int, Form> forms awaiting an answer, by form id */
    private array $forms = [];

    /** @var list<ClientboundPacket> */
    private array $packets = [];

    /**
     * @param self::* $channel
     */
    public function record(string $channel, string $text): void
    {
        $this->texts[$channel][] = $text;
    }

    /**
     * @param self::* $channel
     * @return list<string>
     */
    public function texts(string $channel): array
    {
        return $this->texts[$channel] ?? [];
    }

    public function recordForm(int $id, Form $form): void
    {
        $this->forms[$id] = $form;
    }

    /**
     * @return array<int, Form>
     */
    public function forms(): array
    {
        return $this->forms;
    }

    public function forgetForm(int $id): void
    {
        unset($this->forms[$id]);
    }

    public function clearForms(): void
    {
        $this->forms = [];
    }

    public function recordPacket(ClientboundPacket $packet): void
    {
        $this->packets[] = $packet;
    }

    /**
     * @return list<ClientboundPacket>
     */
    public function packets(): array
    {
        return $this->packets;
    }

    public function clear(): void
    {
        $this->texts = [];
        $this->packets = [];
    }
}
