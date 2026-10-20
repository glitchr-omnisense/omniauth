<?php

namespace Omniauth\Event;

use Omniauth\Context;
use Omniauth\Factor;

/** The proof was good but the account may not sign in (omnibase: the "welcome back" e-mail of a disabled account). */
final class AccountRefused
{
    public function __construct(
        public readonly object $account,
        public readonly string $reason,
        public readonly Factor $factor,
        public readonly Context $context,
    ) {
    }
}
