<?php

namespace Omniauth\Contract;

use Omniauth\Context;
use Omniauth\Factor;

/** The second-factor rule: what an account must still pass. */
interface SecondFactorPolicy
{
    /**
     * The second factors this account must still pass after $passed (none: it is done).
     *
     * @param list<Factor> $passed
     *
     * @return list<Factor> those it may present (any one suffices)
     */
    public function required(object $account, array $passed, Context $context): array;
}
