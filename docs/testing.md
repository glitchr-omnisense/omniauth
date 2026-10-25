---
title: Testing
order: 60
---

# Testing

`Omniauth\Testing\` holds what a host's tests (and a first try) need, with no library:

| Class | What it is |
|---|---|
| `TestAccount` | an account with every proof on it (hash, links, codes, version, identities) |
| `InMemoryStore` | `Accounts`, `Passwords`, `LinkTokens`, `PlainLinkTokens`, `BackupCodes`, `TrustedVersion`, `Identities` over TestAccounts; `emailCodes()` and `totpSecrets()` for the two whose methods share names |
| `RecordingSender` | sends nothing, keeps every `Message`: `last()->secret` is the link's token or the code |
| `FixedClock` | PSR-20, still until `advance($seconds)` or `set()` |
| `ArrayCache` | PSR-16 in an array, on that clock: `CacheThrottle` with no cache library |
| `StubMethod` | a first factor (a passkey, a provider) passing for `['handle' => ...]`, without its library |
| `StubSecondFactor` | a second factor (an authenticator application) enrolled for the handles given |

A host's test drives the conductor with them, here a passkey and an authenticator application
stood in for (run in the [harness](harness.md) on 2026-10-25):

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\Context;
use Omniauth\Factor;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omniauth\Testing\FixedClock;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\StubMethod;
use Omniauth\Testing\StubSecondFactor;
use Omniauth\Testing\TestAccount;

$store = new InMemoryStore(new TestAccount('1', 'ringo', 'ringo@example.org', password_hash('x', PASSWORD_BCRYPT)));
$omniauth = new Omniauth($store, new Methods([
    new PasswordMethod($store, $store),
    new StubMethod(Factor::Passkey, $store),                  // a passkey, without webauthn-lib
    new StubSecondFactor(Factor::Totp, $store, ['1']),        // an authenticator application set up by account 1
]), new ArrayState(), clock: new FixedClock());

echo $omniauth->verify('passkey', new Context(), ['handle' => '1'])->status->value, " by passkey (QA1: no second factor)\n";
echo $omniauth->verify('password', new Context(), ['identifier' => 'ringo', 'password' => 'x'])->status->value, " by password\n";
echo $omniauth->verify('totp', new Context(), ['code' => '123456'])->status->value, " by totp\n";
```

```
authenticated by passkey (QA1: no second factor)
second-factor-required by password
authenticated by totp
```

## The test doubles in detail

`TestAccount` - public properties, every proof on the account:

| Property | Type | Default | Read by |
|---|---|---|---|
| `handle`, `identifier` | `string` | - | `Accounts::handle()`, `identifier()` |
| `email` | `?string` | `null` | `email()`; `byIdentifier()` matches it too, case aside |
| `password` | `?string` | `null` | a `password_hash()` or Symfony hash |
| `name` | `?string` | `null` | `displayName()` (else the identifier) |
| `refused` | `?string` | `null` | a reason: `check()` throws `AccountRefused` with it |
| `totpSecret` | `?string` | `null` | `InMemoryTotpSecrets` |
| `emailCodeEnabled`, `emailCode` | `bool`, `?string` | `false`, `null` | `InMemoryEmailCodes` |
| `trustedVersion` | `int` | `0` | `TrustedVersion` |
| `links`, `plainLinks` | `array<string, int>` | `[]` | a link's hash (or clear value) => until when it serves |
| `backupCodes` | `list<string>` | `[]` | the hashes |
| `identities`, `identityDetails` | `array` | `[]` | provider => subject; provider => `email`, `emailVerified`, `usedAt` |

`InMemoryStore` takes `TestAccount` objects (`add()` adds one later) and refuses any other object
(`InvalidArgumentException`); `emailCodes()` and `totpSecrets()` give the stores of those two
contracts over the same accounts. `RecordingSender` keeps `$sent` (account and message pairs),
`last()` and `count()`. `FixedClock` starts at `2026-10-20 12:00:00 UTC` unless given another time;
`ArrayCache` reads its lifetimes on the clock it is given (the system's otherwise). `StubMethod`
refuses a second factor and `StubSecondFactor` a first one (`InvalidArgumentException`);
`StubSecondFactor`'s code is `123456` unless given another.

## Identity providers in tests

`Omniauth\OAuth\Testing\FakeProvider` stands in for Google and the person consenting there, with no
network ([Identity providers](oauth.md#in-tests)); a provider package checks itself against the
contract with `Omniauth\Testing\ProviderContractTests` ([Conformance suite](oauth.md#conformance-suite)).
The method packages ship their own doubles: omniauth/otp's `Testing\AuthenticatorApp` (the person's
phone), omniauth/webauthn's `Testing\SoftwareAuthenticator` (a P-256 authenticator answering as a
browser) and `Testing\InMemoryPasskeys`.

## In a framework's tests

Under Symfony and Laravel the stores are services: bind the in-memory ones (or the application's
on a test database) in the test environment, and replace the `Sender` with a `RecordingSender` to
read the link or the code a test signs in with. omnibridge's own suites do so (`tests/AuthTest.php`
in omnibridge/symfony and omnibridge/laravel: every method signed in through a kernel or an
application, a simulated Google included).

