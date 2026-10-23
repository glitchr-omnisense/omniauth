<?php

namespace Omniauth;

/**
 * What a person proves when signing in, named once for every method, every
 * policy and every host: the stable key a host's routes, settings and
 * translations use.
 */
enum Factor: string
{
    /** What one knows. */
    case Password = 'password';
    /** The mailbox: a link sent to the account's address. */
    case Link = 'link';
    /** The device and the gesture or the code that unlocks it (omniauth/webauthn). */
    case Passkey = 'passkey';
    /** An identity provider (Omniauth\OAuth\OAuthMethod). */
    case OAuth = 'oauth';
    /** The authenticator application (omniauth/otp). */
    case Totp = 'totp';
    /** A code sent to the account's address once a first factor passed. */
    case EmailCode = 'email-code';
    /** One of the codes handed out once, each good once. */
    case BackupCode = 'backup-code';
    /** A second factor already passed on this browser: the trusted_device cookie. */
    case TrustedDevice = 'trusted-device';

    /** What it can be: a first factor (it finds the account) or a second (the account is known). */
    public function role(): Role
    {
        return match ($this) {
            self::Password, self::Link, self::Passkey, self::OAuth => Role::First,
            self::Totp, self::EmailCode, self::BackupCode, self::TrustedDevice => Role::Second,
        };
    }

    public static function of(self|string $factor): self
    {
        return $factor instanceof self ? $factor : self::from($factor);
    }
}
