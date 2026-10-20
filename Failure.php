<?php

namespace Omniauth;

/**
 * Why an attempt failed: stable keys a page translates (omnibase's
 * "@notifications.login.*" catalogues), never a message.
 */
enum Failure: string
{
    /** No such account, or not that secret: one answer for both. */
    case BadCredentials = 'bad-credentials';
    /** A code (e-mailed, backup, from an application) that is not the right one. */
    case BadCode = 'bad-code';
    /** A sign-in link unknown, already used or past its time. */
    case InvalidLink = 'invalid-link';
    /** What the ceremony began with is gone: a code past its time, an attempt older than its lifetime. */
    case Expired = 'expired';
    /** Too many tries: the throttle refused before anything was checked. */
    case Throttled = 'throttled';
    /** The account exists and the proof is good, but it may not sign in now (Result::$reason: banned, locked...). */
    case AccountRefused = 'account-refused';
    /** A listener of Verifying or Beginning refused (omnishield: not a human); Result::$reason says its word. */
    case Refused = 'refused';
    /** A second factor with no first one passed before it in this attempt. */
    case NoAttempt = 'no-attempt';
    /** A second factor the attempt did not offer. */
    case NotOffered = 'not-offered';
    /** A factor this account has not set up. */
    case NotEnrolled = 'not-enrolled';
}
