<?php

namespace Omniauth\Method;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Proof;

/**
 * A method that first gives something to the browser or the mailbox: options
 * to sign, an address to go to, a link or a code sent. What it must remember
 * goes into the attempt (Attempt::keep()), never into the challenge.
 */
interface Challenging extends Method
{
    /** @param Proof $proof what the visitor sent to begin with (the link's address), often nothing */
    public function begin(Attempt $attempt, Proof $proof, Context $context): Challenge;
}
