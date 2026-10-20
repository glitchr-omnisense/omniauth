<?php

namespace Omniauth;

/**
 * Someone a provider vouched for whom no account is linked to: what a sign-up
 * form fills in, and what links the account once it exists.
 */
final readonly class PendingIdentity
{
    public function __construct(
        public string $provider,
        /** The person's stable identifier at the provider ("sub"). */
        public string $subject,
        public ?string $email = null,
        /** Whether the provider says the address is verified (Google's email_verified). */
        public bool $emailVerified = false,
        public ?string $name = null,
    ) {
    }
}
