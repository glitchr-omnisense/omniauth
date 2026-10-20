<?php

namespace Omniauth;

use Psr\Clock\ClockInterface;

/** The machine's clock, when the host gives none (Testing\FixedClock in tests). */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
