<?php

namespace Omniauth;

/** A first factor finds the account; a second one is asked of an account already found. */
enum Role: string
{
    case First = 'first';
    case Second = 'second';
}
