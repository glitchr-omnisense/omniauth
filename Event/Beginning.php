<?php

namespace Omniauth\Event;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Factor;

/** Before a ceremony begins (a link or a code is about to be sent): a listener may refuse. */
final class Beginning
{
    private ?string $refusal = null;

    public function __construct(
        public readonly Factor $factor,
        public readonly Context $context,
        public readonly Attempt $attempt,
        public readonly ?string $identifier = null,
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
