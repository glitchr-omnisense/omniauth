<?php

namespace Omniauth\Testing;

use Omniauth\Contract\EmailCodes;

/** The e-mailed codes on TestAccount objects (InMemoryStore::emailCodes()). */
final class InMemoryEmailCodes implements EmailCodes
{
    public function enabled(object $account): bool
    {
        return InMemoryStore::of($account)->emailCodeEnabled;
    }

    public function enable(object $account): void
    {
        InMemoryStore::of($account)->emailCodeEnabled = true;
    }

    public function disable(object $account): void
    {
        InMemoryStore::of($account)->emailCodeEnabled = false;
    }

    public function remember(object $account, string $hash): void
    {
        InMemoryStore::of($account)->emailCode = $hash;
    }

    public function remembered(object $account): ?string
    {
        return InMemoryStore::of($account)->emailCode;
    }

    public function forget(object $account): void
    {
        InMemoryStore::of($account)->emailCode = null;
    }
}
