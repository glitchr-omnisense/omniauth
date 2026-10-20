<?php

namespace Omniauth\Event;

use Omniauth\Factor;

/**
 * Before a factor is turned off: omnibase's rule ("the administrator wins":
 * a role that requires a second factor keeps it) refuses here, so a hidden
 * button and a forged POST meet the same predicate.
 */
final class Revoking
{
    private ?string $refusal = null;

    public function __construct(
        public readonly object $account,
        public readonly Factor $factor,
    ) {
    }

    public function refuse(string $reason): void
    {
        $this->refusal = $reason;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }
}
