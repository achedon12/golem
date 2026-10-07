<?php

declare(strict_types=1);

namespace Golem\Runtime;

use Golem\Golem;
use Golem\Runtime\Coroutine\Deferred;
use pocketmine\math\Vector3;
use pocketmine\player\Player;

/**
 * Client-side physics for one golem.
 *
 * A Bedrock client moves itself and tells the server where it went; the server only
 * checks collisions. Golems have no client, so this plays that part: walking toward a
 * target, jumping and falling, one step per tick, always through Player::handleMovement()
 * so walls, PlayerMoveEvent and fall damage work as for a real player.
 *
 * @internal
 */
final class Movement
{
    private const GRAVITY = 0.08;
    private const DRAG = 0.98;
    private const TERMINAL_VELOCITY = 3.92;
    private const JUMP_VELOCITY = 0.42;
    private const STEP_HEIGHT = 0.6;
    private const ARRIVED = 0.15;
    private const STUCK_TICKS = 20;

    private float $verticalVelocity = 0.0;

    private ?Vector3 $target = null;

    private float $blocksPerTick = 0.0;

    /** @var Deferred<Golem>|null */
    private ?Deferred $arrival = null;

    private int $stuckTicks = 0;

    public function __construct(
        private readonly Player $player,
        private readonly Golem $golem,
    ) {
    }

    /**
     * @return Deferred<Golem>
     */
    public function walkTo(Vector3 $target, float $blocksPerSecond): Deferred
    {
        $this->arrival?->reject(new \RuntimeException("{$this->golem->name()} was sent somewhere else before arriving"));

        /** @var Deferred<Golem> $arrival */
        $arrival = new Deferred();
        $this->arrival = $arrival;
        $this->target = $target;
        $this->blocksPerTick = $blocksPerSecond / 20;
        $this->stuckTicks = 0;

        return $arrival;
    }

    public function jump(): bool
    {
        if (!$this->isGrounded()) {
            return false;
        }
        $this->player->jump(); // fires PlayerJumpEvent
        $this->verticalVelocity = self::JUMP_VELOCITY;

        return true;
    }

    public function tick(): void
    {
        if (!$this->player->isConnected() || !$this->player->isAlive()) {
            return;
        }

        $position = $this->player->getPosition();
        $wanted = new Vector3(0, 0, 0);

        if ($this->target !== null) {
            $toTarget = new Vector3($this->target->x - $position->x, 0, $this->target->z - $position->z);
            $distance = $toTarget->length();
            if ($distance <= self::ARRIVED) {
                $this->arrive();
            } else {
                $this->player->lookAt($this->target->withComponents(null, $position->y + $this->player->getEyeHeight(), null));
                $wanted = $toTarget->multiply(min($this->blocksPerTick, $distance) / $distance);
            }
        }

        $grounded = $this->isGrounded();
        if (!$this->player->isFlying() && ($this->verticalVelocity > 0 || !$grounded)) {
            $wanted = $wanted->add(0, $this->verticalVelocity, 0);
            $this->verticalVelocity = max(-self::TERMINAL_VELOCITY, ($this->verticalVelocity - self::GRAVITY) * self::DRAG);
        } else {
            $this->verticalVelocity = 0.0;
        }

        if ($wanted->lengthSquared() === 0.0) {
            return;
        }

        $step = $this->collide($wanted, $grounded);
        if ($step->y !== $wanted->y) {
            $this->verticalVelocity = 0.0; // landed, or bumped a ceiling
        }
        if ($step->lengthSquared() > 0.0) {
            $this->player->handleMovement($position->addVector($step));
        }

        if ($this->target !== null) {
            $horizontal = sqrt($step->x ** 2 + $step->z ** 2);
            $this->stuckTicks = $horizontal < $this->blocksPerTick * 0.2 ? $this->stuckTicks + 1 : 0;
            if ($this->stuckTicks >= self::STUCK_TICKS) {
                $this->fail();
            }
        }
    }

    /**
     * Shortens a movement so it stops at blocks, the way a client does: PocketMine trusts
     * players' positions and does not resolve their collisions itself. Same algorithm as
     * Entity::move(), including stepping up slabs and stairs.
     */
    private function collide(Vector3 $wanted, bool $grounded): Vector3
    {
        $step = $this->sweep($wanted);
        $blockedHorizontally = abs($step->x - $wanted->x) > 1e-7 || abs($step->z - $wanted->z) > 1e-7;
        if ($blockedHorizontally && $grounded) {
            $stepped = $this->sweep(new Vector3($wanted->x, self::STEP_HEIGHT, $wanted->z));
            if ($stepped->x ** 2 + $stepped->z ** 2 > $step->x ** 2 + $step->z ** 2) {
                return $stepped;
            }
        }

        return $step;
    }

    private function sweep(Vector3 $wanted): Vector3
    {
        [$dx, $dy, $dz] = [$wanted->x, $wanted->y, $wanted->z];
        $box = clone $this->player->getBoundingBox();
        $obstacles = $this->player->getWorld()->getBlockCollisionBoxes($box->addCoord($dx, $dy, $dz));

        foreach ($obstacles as $obstacle) {
            $dy = $obstacle->calculateYOffset($box, $dy);
        }
        $box->offset(0, $dy, 0);
        foreach ($obstacles as $obstacle) {
            $dx = $obstacle->calculateXOffset($box, $dx);
        }
        $box->offset($dx, 0, 0);
        foreach ($obstacles as $obstacle) {
            $dz = $obstacle->calculateZOffset($box, $dz);
        }

        return new Vector3($dx, $dy, $dz);
    }

    private function isGrounded(): bool
    {
        $feet = $this->player->getBoundingBox()->offsetCopy(0, -0.05, 0);

        return $this->player->getWorld()->getBlockCollisionBoxes($feet) !== [];
    }

    /**
     * A plugin failed while this golem moved (in a PlayerMoveEvent listener, say): fail
     * the walk in progress with that error.
     *
     * @return bool false when no walk was in progress to report it
     */
    public function abort(\Throwable $error): bool
    {
        $arrival = $this->arrival;
        $this->target = null;
        $this->arrival = null;
        $this->verticalVelocity = 0.0;
        $arrival?->reject($error);

        return $arrival !== null;
    }

    private function arrive(): void
    {
        $arrival = $this->arrival;
        $this->target = null;
        $this->arrival = null;
        $arrival?->resolve($this->golem);
    }

    private function fail(): void
    {
        $arrival = $this->arrival;
        $where = $this->player->getPosition()->floor();
        $this->target = null;
        $this->arrival = null;
        $arrival?->reject(new \RuntimeException(sprintf(
            '%s is stuck at (%d, %d, %d) and cannot reach its destination: a wall, a hole or a cancelled PlayerMoveEvent?',
            $this->golem->name(),
            $where->x,
            $where->y,
            $where->z,
        )));
    }
}
