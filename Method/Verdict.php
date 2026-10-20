<?php

namespace Omniauth\Method;

use Omniauth\Failure;
use Omniauth\PendingIdentity;

/** What a method concluded of a proof. */
final readonly class Verdict
{
    private function __construct(
        public ?object $account,
        public ?Failure $failure,
        public ?string $reason = null,
        public bool $remember = false,
        public ?PendingIdentity $identity = null,
    ) {
    }

    /** @param bool $remember the method itself asks the host for its remember-me (a link from a mailbox) */
    public static function passed(object $account, bool $remember = false): self
    {
        return new self($account, null, null, $remember);
    }

    public static function refused(Failure $failure, ?string $reason = null): self
    {
        return new self(null, $failure, $reason);
    }

    public static function expired(): self
    {
        return new self(null, Failure::Expired);
    }

    /** A provider vouched for someone no account is linked to. */
    public static function unknown(PendingIdentity $identity): self
    {
        return new self(null, null, null, false, $identity);
    }

    public function hasPassed(): bool
    {
        return null !== $this->account;
    }
}
