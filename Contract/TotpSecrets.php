<?php

namespace Omniauth\Contract;

/** The authenticator applications' secrets, base32 (omnibase: user.secret); read by omniauth/otp (SealedTotpSecrets seals them at rest). */
interface TotpSecrets
{
    public function secret(object $account): ?string;

    public function enable(object $account, string $secret): void;

    public function disable(object $account): void;
}
