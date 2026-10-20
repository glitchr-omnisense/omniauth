<?php

namespace Omniauth\Contract;

/**
 * The sign-in links in flight. The store sees their SHA-256, never their
 * value: whoever reads the table cannot sign in with it.
 */
interface LinkTokens
{
    /** Keeps a link issued to the account until that moment (beside the others, or in their place: the store's choice). */
    public function issue(object $account, string $hash, \DateTimeImmutable $until): void;

    /**
     * The account the link of that hash was issued to, when it is still valid
     * at $now; the link is used up on the way: it serves once.
     */
    public function consume(string $hash, \DateTimeImmutable $now): ?object;
}
