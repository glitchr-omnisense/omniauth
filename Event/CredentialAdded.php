<?php

namespace Omniauth\Event;

use Omniauth\Factor;

/** A factor was set up, or a password changed. */
final class CredentialAdded
{
    public function __construct(
        public readonly object $account,
        public readonly Factor $factor,
    ) {
    }
}
