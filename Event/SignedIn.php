<?php

namespace Omniauth\Event;

use Omniauth\Context;
use Omniauth\Factor;

/** Signed in: omnibase's UserTracker, the "new device" e-mail, the account brought up to date listen here. */
final class SignedIn
{
    /** @param list<Factor> $passed */
    public function __construct(
        public readonly object $account,
        public readonly array $passed,
        public readonly Context $context,
    ) {
    }
}
