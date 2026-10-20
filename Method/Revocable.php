<?php

namespace Omniauth\Method;

/** A factor an account can turn off (or, for trusted devices, forget every browser). */
interface Revocable extends Method
{
    public function revoke(object $account): void;
}
