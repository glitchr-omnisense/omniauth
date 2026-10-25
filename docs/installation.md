---
title: Installation
order: 10
---

# Installation

```sh
composer require omniscient/omniauth                       # password, link, code by e-mail, backup codes, trusted devices, identity providers
composer require omniauth/otp omniauth/webauthn omniauth/google   # an authenticator application, passkeys, Google: one package each
```

## Requirements

- PHP 8.2 or later.
- `omniscient/omniauth` requires `psr/cache` (^2.0 or ^3.0), `psr/simple-cache` (^2.0 or ^3.0),
  `psr/clock` (^1.0), `psr/event-dispatcher` (^1.0) and `symfony/password-hasher` (^6.4, ^7.0 or
  ^8.0), a component that loads no framework. Nothing else: no ORM, no HTTP client, no OAuth
  library.
- It suggests `league/oauth2-client` (`Omniauth\OAuth\Provider\LeagueProvider`: the provider
  packages require it), `symfony/cache` (a PSR-6 pool or PSR-16 cache for `CacheThrottle` in plain
  PHP), `phpunit/phpunit` (the providers' conformance suite) and `omniscient/omnibridge` (the hosts'
  contracts, among them `Omnibridge\Contract\Tokens` for a form's CSRF token).
- `omniauth/otp` adds `ext-sodium`, spomky-labs/otphp and paragonie/constant_time_encoding;
  `omniauth/webauthn` adds `ext-openssl`, web-auth/webauthn-lib, symfony/serializer and symfony/uid;
  `omniauth/google` adds league/oauth2-client and league/oauth2-google (Guzzle through them).

## Plain PHP

No framework, no ORM: the accounts and their proofs stay in your tables, behind small store
contracts ([The contracts](contracts.md)). The whole thing by hand - the in-memory stores of
`Omniauth\Testing` stand in for yours:

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\BackupCode\BackupCodeMethod;
use Omniauth\CacheThrottle;
use Omniauth\Context;
use Omniauth\EmailCode\EmailCodeMethod;
use Omniauth\Link\LinkMethod;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omniauth\Testing\ArrayCache;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\RecordingSender;
use Omniauth\Testing\TestAccount;
use Omniauth\TrustedDevice\TrustedDevices;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

$secret = 'the-application-secret-32-bytes-at-least';     // Symfony's kernel.secret, Laravel's APP_KEY...
$hasher = new NativePasswordHasher();                      // bcrypt, cost 13: Symfony's "auto"

// Your stores: here one account with a password and the code by e-mail.
$store = new InMemoryStore(new TestAccount('42', 'ringo', 'ringo@example.org', $hasher->hash('yellow-submarine'), emailCodeEnabled: true));
$sender = new RecordingSender();                           // yours sends an e-mail

$session = [];                                             // $_SESSION, in a real page: ArrayState::session()
$omniauth = new Omniauth(
    accounts: $store,
    methods: new Methods([
        new PasswordMethod($store, $store, $hasher),
        new LinkMethod($store, $store, $sender),
        new EmailCodeMethod($store, $store->emailCodes(), $sender, $secret),
        new BackupCodeMethod($store),
        new TrustedDevices($store, $store, $secret),
    ]),
    state: new ArrayState($session),
    throttle: new CacheThrottle(new ArrayCache()),         // any PSR-6 pool or PSR-16 cache
);

// What your page knows of the request.
$context = new Context(origin: 'https://example.org', host: 'example.org', ip: '203.0.113.7', trustedDevice: $_COOKIE['trusted_device'] ?? null);

$result = $omniauth->verify('password', $context, ['identifier' => 'ringo@example.org', 'password' => 'yellow-submarine']);
echo $result->status->value, ': ', implode(', ', array_map(fn ($f) => $f->value, $result->offered)), "\n";
// second-factor-required: email-code

$omniauth->begin('email-code', $context);                  // the code goes out
$code = $sender->last()->secret;                           // what the person reads in the mail

$result = $omniauth->verify('email-code', $context, ['code' => $code, 'trust' => true]);
echo $result->status->value, ' as ', $result->account->identifier, ' by ', implode(' + ', array_map(fn ($f) => $f->value, $result->passed)), "\n";
// authenticated as ringo by password + email-code

echo $result->trustDevice ? "trusted_device cookie to set\n" : '';
// setcookie('trusted_device', $result->trustDevice, ...): next time, no code
```

What your page does with the result is the host's part: `Authenticated` - renew the session id
(`session_regenerate_id(true)`) and keep the account's handle in the session; set a
remember-me when `$result->remember`; set the `trusted_device` cookie when `$result->trustDevice`
is given. `SecondFactorRequired` - show the second-factor page with `$result->offered`. `Failed` -
translate `$result->failure` (a stable key: `bad-credentials`, `throttled`, `account-refused`...).
Check your form's CSRF token before calling (`Omnibridge\Contract\Tokens` under a bridged host).

`docker/harness/bin/bare` is a whole script of the same, every method in turn; `Tests/DocsTest`
runs the block above as it is written here.

Run in the [harness](harness.md) on 2026-10-25 (PHP 8.4), the script prints:

```
second-factor-required: email-code
authenticated as ringo by password + email-code
trusted_device cookie to set
```

The pieces:

- the **methods**, one per factor, each over the stores it reads (`PasswordMethod` over
  `Passwords`, `EmailCodeMethod` over `EmailCodes` and a `Sender`...), mounted in a
  `Method\Methods`;
- the **conductor**, `Omniauth`, over the accounts, the methods, the visitor's `State` (here an
  array; the session under a framework) and optionally a `Throttle`, a PSR-14 dispatcher, a
  PSR-20 clock and a second-factor rule (by default `EnrolledSecondFactors`);
- a **`Context`** per request (origin, host, address, browser, the `trusted_device` cookie, the
  firewall's name) and the **proof** the visitor sent, as an array.

The same script with Google in place of the password is the [identity providers](oauth.md)
page's; with an authenticator application, omniauth/otp's installation page; with a passkey,
omniauth/webauthn's.

## In a framework

- **Symfony**: omnibridge/symfony's `OmnibridgeBundle` registers omniauth's extension where
  omniauth is installed; `config/packages/omniauth.yaml` turns each method on, and one
  authenticator, `Omnibridge\Symfony\Auth\OmniauthAuthenticator`, signs in by all of them:
  [Symfony](symfony.md).
- **Laravel**: omnibridge/laravel's `Auth\AuthServiceProvider`, on with `config/omnibridge.php`'s
  `'auth' => true`; `config/omniauth.php` takes the same keys as `omniauth.yaml`, and the guard
  driver `omniauth` signs in: [Laravel](laravel.md).
- **Any other host**: build the conductor once per request (or once per worker, with a `State`
  that reads the current request's session), as the script above does, and answer its `Result`
  as described above.
