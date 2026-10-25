---
title: Configuration reference
order: 40
---

# Configuration reference

omniauth has no configuration file of its own: each class takes its options in its constructor.
The hosts' files - `config/packages/omniauth.yaml` ([Symfony](symfony.md#the-configuration-tree))
and `config/omniauth.php` ([Laravel](laravel.md#configomniauthphp)) - are the bridges' and build
these classes from their keys.

## The conductor's options

`new Omniauth(...)`:

| Argument | Type | Default | |
|---|---|---|---|
| `accounts` | `Contract\Accounts` | required | |
| `methods` | `Method\Methods` | required | one method per factor |
| `state` | `Contract\State` | required | where the attempt lives between two requests |
| `policy` | `?Contract\SecondFactorPolicy` | `null`: `new EnrolledSecondFactors($methods)` | |
| `throttle` | `?Contract\Throttle` | `null`: no limit | |
| `events` | `?Psr\EventDispatcher\EventDispatcherInterface` | `null`: no event, nothing refused | |
| `clock` | `?Psr\Clock\ClockInterface` | `null`: `SystemClock` | an attempt's age |
| `lifetime` | `int` | `600` (`Omniauth::LIFETIME`) | seconds an attempt (and an enrolment) lasts |

## The methods of the core

| Class | Argument | Type | Default |
|---|---|---|---|
| `Password\PasswordMethod` | `accounts`, `passwords` | `Accounts`, `Passwords` | required |
| | `hasher` | `?Symfony\Component\PasswordHasher\PasswordHasherInterface` | `null`: `NativePasswordHasher` (bcrypt, cost 13: Symfony's "auto") |
| `Link\LinkMethod` | `accounts`, `tokens`, `sender` | `Accounts`, `LinkTokens`, `Sender` | required |
| | `lifetime` | `int`, seconds | `900` (`LinkMethod::LIFETIME`) |
| | `clock` | `?ClockInterface` | `null`: `SystemClock` |
| `EmailCode\EmailCodeMethod` | `accounts`, `codes`, `sender` | `Accounts`, `EmailCodes`, `Sender` | required |
| | `secret` | `string`, not empty | required: keys the codes' hashes (the host's secret) |
| | `digits` | `int`, 4 to 9 | `6` |
| | `lifetime` | `int`, seconds | `600` (`EmailCodeMethod::LIFETIME`) |
| | `clock` | `?ClockInterface` | `null` |
| `BackupCode\BackupCodeMethod` | `codes` | `BackupCodes` | required |
| | `count` | `int` | `8`: codes drawn at each set-up |
| `TrustedDevice\TrustedDevices` | `accounts`, `versions` | `Accounts`, `TrustedVersion` | required |
| | `secret` | `string`, 32 bytes or more | required: signs the tokens (Symfony's `kernel.secret`) |
| | `lifetime` | `int`, seconds | `2592000` (`TrustedDevices::LIFETIME`, 30 days) |
| | `clock` | `?ClockInterface` | `null` |
| `OAuth\OAuthMethod` | `accounts`, `identities`, `providers` | `Accounts`, `Identities`, `Provider\Providers` | required |
| | `byEmail` | `Contract\EmailLinking\|bool` | `true`: any account linked by an address the provider vouches for; `false`: never; `NoSecondFactorLinking`: never one with a second factor |
| | `clock` | `?ClockInterface` | `null` |

The packages' methods: omniauth/otp's `TotpMethod` (`leeway` 0, `period` 30, `digits` 6,
`algorithm` `sha1`, `issuer`, `server`, `clock`) and omniauth/webauthn's `PasskeyMethod`
(`rp`: a `RelyingParty` with `id`, `name`, `origins`, `insecure`; `timeout`) - their manuals list
every option.

## The second-factor rule

`new EnrolledSecondFactors(Methods $methods, array $after = EnrolledSecondFactors::AFTER)`:
`$after` lists the first factors a second one follows, `[Factor::Password, Factor::Link,
Factor::OAuth]` by default ([The second-factor rule](second-factor.md)).
`new NoSecondFactorLinking(Methods $methods)` reads the methods when it is asked, so it can be built
before the OAuth method is added to them.

## The throttle's limits

`new CacheThrottle($cache, array $limits = [], ?ClockInterface $clock = null, string $prefix =
'omniauth.throttle.')` counts the tries in any PSR-6 pool (`CacheItemPoolInterface`) or PSR-16
cache (`CacheInterface`): a fixed window per scope and key, `[tries, seconds]`. `$limits` replaces
the defaults scope by scope:

| Scope | Tries | Window | What it limits |
|---|---|---|---|
| `password` | 5 | 60 s | password checks, per identifier and visitor's address |
| `link.begin` | 3 | 3600 s | sign-in links sent, per identifier and visitor's address |
| `email-code` | 5 | 900 s | e-mailed codes checked, per account |
| `email-code.begin` | 3 | 900 s | e-mailed codes sent, per account |
| `backup-code` | 5 | 900 s | backup codes checked, per account |
| `totp` | 5 | 900 s | authenticator codes checked, per account |
| `*` | 5 | 60 s | any other scope: `link`, `oauth`, `oauth.begin`, `passkey`, `passkey.begin`, `trusted-device`... |

The scopes are the factor's value for a verification (and an enrolment's confirmation), the
factor's value and `.begin` for a beginning. The keys are the conductor's: `<identifier in lower
case>|<ip>` for a first factor (`-` without an address), `account:<handle>` once the account is
known. A try is counted before the check; a success resets its scope and key. The window starts at
the first try and lasts its seconds; the cache entry expires with it. Keys are hashed (SHA-256,
under `$prefix`), so any identifier is a valid cache key.

```php
use Omniauth\CacheThrottle;
use Symfony\Component\Cache\Adapter\RedisAdapter;

$throttle = new CacheThrottle(new RedisAdapter($redis), ['password' => [10, 300]]);   // 10 tries in 5 minutes
```

Three tries in five minutes, then the same account from another address (run in the
[harness](harness.md) on 2026-10-25, on `Testing\ArrayCache` and a `FixedClock`):

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\CacheThrottle;
use Omniauth\Context;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omniauth\Testing\ArrayCache;
use Omniauth\Testing\FixedClock;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\TestAccount;

$clock = new FixedClock();
$store = new InMemoryStore(new TestAccount('42', 'ringo', 'ringo@example.org', password_hash('yellow-submarine', PASSWORD_BCRYPT)));
$omniauth = new Omniauth(
    accounts: $store,
    methods: new Methods([new PasswordMethod($store, $store)]),
    state: new ArrayState(),
    throttle: new CacheThrottle(new ArrayCache($clock), ['password' => [3, 300]], $clock),   // 3 tries in 5 minutes
    clock: $clock,
);
$context = new Context(ip: '203.0.113.7');

foreach (['a', 'b', 'c', 'yellow-submarine'] as $password) {
    echo $omniauth->verify('password', $context, ['identifier' => 'ringo', 'password' => $password])->failure?->value ?? 'authenticated', "\n";
}
$clock->advance(300);
echo $omniauth->verify('password', $context, ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->status->value, " after 5 minutes\n";
echo $omniauth->verify('password', new Context(ip: '198.51.100.9'), ['identifier' => 'ringo', 'password' => 'a'])->failure->value, " from another address\n";
```

```
bad-credentials
bad-credentials
bad-credentials
throttled
authenticated after 5 minutes
bad-credentials from another address
```

> [!NOTE]
> `CacheThrottle` reads, then writes: two requests at the same instant may both pass the last
> try. A host that needs the limit exact under concurrency implements `Contract\Throttle` on a
> store that counts atomically (a rate limiter with a lock).

## The identity providers

`Providers::configured(array $config, ProviderFactory ...$factories)` reads, per entry (its key the
provider's name):

| Key | | |
|---|---|---|
| `client_id` | string | empty or missing: the entry is left out (a provider without credentials is not served) |
| `redirect_uri` | string | required for an entry with a `client_id` (`InvalidArgumentException`): an absolute address, or a path the trip's origin completes |
| `type` | string | the kind; missing: the entry's name. The first factory that makes the entry wins; none: `InvalidArgumentException` naming the package to install |
| `client_secret`, `scopes`, ... | | the kind's own keys, read by its factory (omniauth/google: `client_secret`, `scopes`, `hosted_domain`) |

`new Providers(Provider ...$providers)` holds providers given as they are; the two combine:
`new Providers($given, ...Providers::configured($config, $factory))`. A second provider of the same
name replaces the first. `Providers::fromConfig()` is deprecated (one release): it makes the Google
entries through omniauth/google's factory.

`LeagueProvider` and `FakeProvider` take their options in their constructors
([Identity providers](oauth.md#over-leagueoauth2-client)).

## The state

`new ArrayState(array &$values = [])` keeps the attempt in a PHP array held by reference;
`ArrayState::session()` over `$_SESSION` (after `session_start()`). The keys it writes are
`omniauth.attempt` and `omniauth.enrolment.<factor>`.
