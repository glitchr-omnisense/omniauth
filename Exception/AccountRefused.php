<?php

namespace Omniauth\Exception;

/** Thrown by Accounts::check(): the account may not sign in now, for a stable reason the page translates. */
final class AccountRefused extends \RuntimeException implements OmniauthException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The account may not sign in: %s.', $reason), 0, $previous);
    }
}
