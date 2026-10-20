<?php

namespace Omniauth;

use Omniauth\Contract\SecondFactorPolicy;
use Omniauth\Method\Methods;

/**
 * The core's second-factor rule: an account that set up a second factor
 * (an authenticator application, the code by e-mail) must pass one of them -
 * or a backup code - after a first factor of $after; a second factor already
 * passed (a trusted device among them) is enough.
 *
 * $after holds Marco's decisions of 2026-10-20: QA1, no second factor after
 * a passkey (it already proves the device and the gesture that unlocks it:
 * Passkey is not in the list); QA2, a second factor after an identity
 * provider (OAuth is). Backup codes alone ask for nothing: they stand in for
 * another second factor, as in scheb/2fa.
 *
 * omnibase decorates it with its SecurityPolicy (a role that requires a
 * second factor and has none is sent to enrol; the demo account never is).
 */
final class EnrolledSecondFactors implements SecondFactorPolicy
{
    public const AFTER = [Factor::Password, Factor::Link, Factor::OAuth];

    /** @param list<Factor> $after the first factors a second one follows */
    public function __construct(
        private readonly Methods $methods,
        private readonly array $after = self::AFTER,
    ) {
    }

    public function required(object $account, array $passed, Context $context): array
    {
        if (!array_intersect(array_map(static fn (Factor $f) => $f->value, $passed), array_map(static fn (Factor $f) => $f->value, $this->after))) {
            return [];
        }
        foreach ($passed as $factor) {
            if (Role::Second === $factor->role()) {
                return [];
            }
        }
        $enrolled = [];
        foreach ($this->methods->secondFactors() as $method) {
            if ($method->enrolled($account)) {
                $enrolled[] = $method->factor();
            }
        }
        $triggers = array_values(array_filter($enrolled, static fn (Factor $f) => Factor::BackupCode !== $f));
        if (!$triggers) {
            return [];
        }

        return \in_array(Factor::BackupCode, $enrolled, true) ? [...$triggers, Factor::BackupCode] : $triggers;
    }
}
