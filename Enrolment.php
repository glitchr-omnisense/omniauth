<?php

namespace Omniauth;

use Omniauth\Method\Challenge;

/**
 * Setting a factor up for an account, in the same two steps as a sign-in:
 * begin() (a code sent, a secret, options to sign, or nothing), confirm()
 * (what the visitor sent back; the secrets to show once).
 */
final readonly class Enrolment
{
    public function __construct(
        private Omniauth $omniauth,
        public Factor $factor,
        public object $account,
    ) {
    }

    /** @param Proof|array<string, mixed> $proof */
    public function begin(Context $context, Proof|array $proof = []): Challenge
    {
        return $this->omniauth->enrolBegin($this->factor, $this->account, $context, $proof);
    }

    /** @param Proof|array<string, mixed> $proof */
    public function confirm(Context $context, Proof|array $proof = []): Enrolled
    {
        return $this->omniauth->enrolConfirm($this->factor, $this->account, $context, $proof);
    }
}
