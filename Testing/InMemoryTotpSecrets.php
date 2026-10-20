<?php

namespace Omniauth\Testing;

use Omniauth\Contract\TotpSecrets;

/** The TOTP secrets on TestAccount objects (InMemoryStore::totpSecrets()), for omniauth/totp's tests. */
final class InMemoryTotpSecrets implements TotpSecrets
{
    public function secret(object $account): ?string
    {
        return InMemoryStore::of($account)->totpSecret;
    }

    public function enable(object $account, string $secret): void
    {
        InMemoryStore::of($account)->totpSecret = $secret;
    }

    public function disable(object $account): void
    {
        InMemoryStore::of($account)->totpSecret = null;
    }
}
