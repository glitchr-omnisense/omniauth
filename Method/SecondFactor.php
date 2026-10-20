<?php

namespace Omniauth\Method;

/** A second factor an account sets up: the policy asks it whether this one has. */
interface SecondFactor extends Method
{
    public function enrolled(object $account): bool;
}
