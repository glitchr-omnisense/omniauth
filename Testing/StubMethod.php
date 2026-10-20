<?php

namespace Omniauth\Testing;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Method;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Omniauth\Role;

/**
 * A first factor that passes for the account of a handle: a passkey or an
 * identity provider without their library, so a host's tests (and the
 * core's) drive the conductor and the policy. Proof: ['handle' => ...].
 */
final class StubMethod implements Method
{
    public function __construct(
        private readonly Factor $factor,
        private readonly Accounts $accounts,
    ) {
        if (Role::First !== $factor->role()) {
            throw new \InvalidArgumentException('StubMethod stands for a first factor; StubSecondFactor for a second one.');
        }
    }

    public function factor(): Factor
    {
        return $this->factor;
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = '' !== $proof->string('handle') ? $this->accounts->byHandle($proof->string('handle')) : null;

        return null !== $account ? Verdict::passed($account) : Verdict::refused(Failure::BadCredentials);
    }
}
