<?php

namespace Omniauth\Contract;

/**
 * The trusted devices' version (omnibase: user.trustedTokenVersion, scheb's
 * TrustedDeviceInterface): a token of another version no longer counts.
 */
interface TrustedVersion
{
    public function version(object $account): int;

    /** Raises the version: every device trusted so far falls back to the second factor. */
    public function revokeAll(object $account): void;
}
