<?php

namespace Omniauth;

/**
 * What a verification ended with: who, by which factors, what remains to
 * pass, and what the host does next (open its session, set its remember-me,
 * set the trusted-device cookie).
 */
final readonly class Result
{
    /**
     * @param list<Factor> $passed
     * @param list<Factor> $offered
     */
    public function __construct(
        public Status $status,
        public ?object $account = null,
        /** The factors passed in this attempt, in their order. */
        public array $passed = [],
        /** SecondFactorRequired: what the second-factor page offers (any one suffices). */
        public array $offered = [],
        public ?Failure $failure = null,
        /** AccountRefused: the account's reason (banned, locked...); Refused: the listener's. */
        public ?string $reason = null,
        /** The host sets its own remember-me. */
        public bool $remember = false,
        /** The trusted_device cookie's new value, to set as is (scheb/2fa's format). */
        public ?string $trustDevice = null,
        /** IdentityUnknown: what a sign-up form fills in. */
        public ?PendingIdentity $identity = null,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return Status::Authenticated === $this->status;
    }

    public function needsSecondFactor(): bool
    {
        return Status::SecondFactorRequired === $this->status;
    }

    public static function failed(Failure $failure, ?string $reason = null, ?object $account = null): self
    {
        return new self(Status::Failed, $account, failure: $failure, reason: $reason);
    }
}
