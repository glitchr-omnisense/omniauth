<?php

namespace Omniauth\Contract;

/**
 * The code sent by e-mail (omnibase: user.emailAuthEnabled,
 * user.emailAuthCode). The store keeps the code's keyed hash, never the code;
 * its deadline is kept in the attempt.
 */
interface EmailCodes
{
    public function enabled(object $account): bool;

    public function enable(object $account): void;

    public function disable(object $account): void;

    public function remember(object $account, string $hash): void;

    public function remembered(object $account): ?string;

    public function forget(object $account): void;
}
