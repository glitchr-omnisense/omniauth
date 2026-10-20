---
title: Installation
order: 1
---

# Installation, in plain PHP

```sh
composer require omniscient/omniauth
```

It requires PHP 8.2, four PSR interfaces (cache, simple-cache, clock, event-dispatcher) and
`symfony/password-hasher`, a standalone component. No framework, no ORM: the accounts and their
proofs stay in your tables, behind small store contracts ([contracts](contracts.md)).

The whole thing by hand - the in-memory stores of `Omniauth\Testing` stand in for yours:

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

Next: [the contracts](contracts.md), then a page per method - [password](password.md),
[link](link.md), [code by e-mail](email-code.md), [backup codes](backup-codes.md),
[trusted devices](trusted-devices.md) - [the second-factor rule](second-factor.md),
[events](events.md), [testing](testing.md), [Symfony and Laravel](hosts.md).
