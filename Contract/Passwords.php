<?php

namespace Omniauth\Contract;

/** The accounts' password hashes (omnibase: user.password). */
interface Passwords
{
    /** The hash as the hasher wrote it, or null when the account has no password. */
    public function hash(object $account): ?string;

    /** Keeps a new hash: a rehash at sign-in, or a new password. */
    public function update(object $account, string $hash): void;
}
