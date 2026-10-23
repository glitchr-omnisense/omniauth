<?php

namespace Omniauth\Contract;

/**
 * The accounts linked to an identity provider, by the person's identifier
 * there ("sub"); read by Omniauth\OAuth\OAuthMethod (omnibase: the userIdentity table).
 * One subject of a provider has one account; an account has one identity per
 * provider.
 */
interface Identities
{
    public function owner(string $provider, string $subject): ?object;

    /**
     * Links the account to that identity, with what the provider said of the
     * address at that moment ($email, and whether it vouched for it).
     */
    public function link(object $account, string $provider, string $subject, ?string $email = null, bool $emailVerified = false): void;

    /** Unlinks the account from that provider (the settings' "disconnect"); nothing when it was not linked. */
    public function unlink(object $account, string $provider): void;

    /** The provider vouched for that identity again (a sign-in by it): its last-used date. */
    public function used(string $provider, string $subject, \DateTimeImmutable $at): void;

    /** @return list<string> the providers the account is linked to */
    public function providers(object $account): array;
}
