<?php

namespace Omniauth\Event;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Factor;

/**
 * Before a method checks a proof (and after the throttle let it through): a
 * listener may refuse - omnishield when the visitor is not a human, omnibase's
 * SignInGuard when a captcha is due.
 */
final class Verifying
{
    private ?string $refusal = null;

    public function __construct(
        public readonly Factor $factor,
        public readonly Context $context,
        public readonly Attempt $attempt,
        /** What the visitor typed to be found (first factors), or null. */
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
