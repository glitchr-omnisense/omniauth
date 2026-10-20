<?php

namespace Omniauth\Contract;

use Omniauth\Exception\AccountRefused;

/**
 * The application's accounts: opaque objects to omniauth, which implement
 * nothing of it (omnibase fills this in on its UserRepository).
 */
interface Accounts
{
    /** By what the person types: an address, a user name. */
    public function byIdentifier(string $identifier): ?object;

    /** By the stable handle (the id; it is also the passkeys' userHandle). */
    public function byHandle(string $handle): ?object;

    public function handle(object $account): string;

    /**
     * What the account is known by to its host: Symfony's getUserIdentifier().
     * The trusted-device tokens carry it (scheb/2fa's "usr" claim).
     */
    public function identifier(object $account): string;

    public function displayName(object $account): string;

    public function email(object $account): ?string;

    /**
     * May the account sign in now? Throws AccountRefused (banned, locked,
     * restricted, disabled...) with a stable reason the page translates.
     *
     * @throws AccountRefused
     */
    public function check(object $account): void;
}
