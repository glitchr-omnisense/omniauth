<?php

namespace Omniauth\Contract;

/** The accounts linked to an identity provider, by the person's identifier there ("sub"); read by omniauth/oauth. */
interface Identities
{
    public function owner(string $provider, string $subject): ?object;

    public function link(object $account, string $provider, string $subject): void;

    /** @return list<string> the providers the account is linked to */
    public function providers(object $account): array;
}
