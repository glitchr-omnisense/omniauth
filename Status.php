<?php

namespace Omniauth;

/** Where an attempt stands after a verification. */
enum Status: string
{
    /** Signed in: the host opens its session. */
    case Authenticated = 'authenticated';
    /** A first factor passed; one of Result::$offered must follow. */
    case SecondFactorRequired = 'second-factor-required';
    /** A provider vouched for someone no account is linked to (Result::$identity: what a sign-up form fills in). */
    case IdentityUnknown = 'identity-unknown';
    /** Nothing passed: Result::$failure says why. */
    case Failed = 'failed';
}
