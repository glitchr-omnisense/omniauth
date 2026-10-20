<?php

namespace Omniauth\Testing;

/**
 * An account for tests and first steps, every proof on it: what
 * InMemoryStore reads and writes.
 */
final class TestAccount
{
    /** @var array<string, int> the sign-in links' hashes, and until when each serves */
    public array $links = [];
    /** @var array<string, int> links issued before the switch, kept in clear (Contract\PlainLinkTokens) */
    public array $plainLinks = [];
    /** @var list<string> */
    public array $backupCodes = [];
    /** @var array<string, string> provider => subject */
    public array $identities = [];

    public function __construct(
        public string $handle,
        public string $identifier,
        public ?string $email = null,
        /** A password_hash() or Symfony hash. */
        public ?string $password = null,
        public ?string $name = null,
        /** Why it may not sign in (banned, locked...), or null. */
        public ?string $refused = null,
        public ?string $totpSecret = null,
        public bool $emailCodeEnabled = false,
        public ?string $emailCode = null,
        public int $trustedVersion = 0,
    ) {
    }
}
