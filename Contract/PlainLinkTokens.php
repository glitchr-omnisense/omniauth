<?php

namespace Omniauth\Contract;

/**
 * For the switch only: the links issued before omniauth were kept in clear
 * (omnibase's userToken "login-token"). A LinkTokens store that still holds
 * some also implements this, and they are read as they are until they
 * expire; once none is left, the store stops implementing it.
 */
interface PlainLinkTokens
{
    public function consumePlain(string $value, \DateTimeImmutable $now): ?object;
}
