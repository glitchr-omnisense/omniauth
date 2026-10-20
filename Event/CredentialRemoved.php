<?php

namespace Omniauth\Event;

use Omniauth\Factor;

/** A factor was turned off; the trusted devices fell back with it. */
final class CredentialRemoved
{
    public function __construct(
        public readonly object $account,
        public readonly Factor $factor,
    ) {
    }
}
