<?php

namespace Omniauth\Testing;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\SecondFactor;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Omniauth\Role;

/**
 * A second factor (an authenticator application without otphp) enrolled for
 * the handles given, passing on the code given. Proof: ['code' => ...].
 */
final class StubSecondFactor implements SecondFactor
{
    /** @param list<string> $enrolled the handles that set it up */
    public function __construct(
        private readonly Factor $factor,
        private readonly Accounts $accounts,
        private readonly array $enrolled = [],
        private readonly string $code = '123456',
    ) {
        if (Role::Second !== $factor->role()) {
            throw new \InvalidArgumentException('StubSecondFactor stands for a second factor; StubMethod for a first one.');
        }
    }

    public function factor(): Factor
    {
        return $this->factor;
    }

    public function enrolled(object $account): bool
    {
        return \in_array($this->accounts->handle($account), $this->enrolled, true);
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = $attempt->account();
        if (null === $account) {
            return Verdict::refused(Failure::NoAttempt);
        }

        return hash_equals($this->code, $proof->string('code')) ? Verdict::passed($account) : Verdict::refused(Failure::BadCode);
    }
}
