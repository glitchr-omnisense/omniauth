<?php

namespace Omniauth\Testing;

use Psr\Clock\ClockInterface;

/** A clock that stands still until told: deadlines and windows tested to the second. */
final class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = '2026-10-20 12:00:00 UTC')
    {
        $this->now = \is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $this->now = \is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('%+d seconds', $seconds));
    }
}
