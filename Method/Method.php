<?php

namespace Omniauth\Method;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Factor;
use Omniauth\Proof;

/** A way to sign in: it checks a proof within an attempt, and touches neither request nor response. */
interface Method
{
    public function factor(): Factor;

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict;
}
