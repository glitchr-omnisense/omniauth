<?php

namespace Omniauth\Method;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Enrolled;
use Omniauth\Proof;

/**
 * A method an account sets up from its settings, in the same two steps as a
 * sign-in. The attempt is the enrolment's own (its account identified), kept
 * apart from any sign-in.
 */
interface Enrolling extends Method
{
    /** What the visitor needs to set it up: a code sent, a secret and its otpauth:// address, options to sign, or nothing. */
    public function enrolBegin(Attempt $enrolment, Proof $proof, Context $context): Challenge;

    /** Checks what the visitor sent back and keeps the credential; the secrets to show once, if any. */
    public function enrolConfirm(Attempt $enrolment, Proof $proof, Context $context): Enrolled;
}
