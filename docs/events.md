---
title: Events
order: 38
---

## Events

PSR-14, optional: pass any `EventDispatcherInterface` as `events:`.

| Event | When | A listener may |
|---|---|---|
| `Beginning` | before a ceremony begins (a link or a code about to go out) | `refuse($reason)` |
| `Verifying` | before a method checks a proof, after the throttle | `refuse($reason)`: omnishield (not a human), omnibase's SignInGuard (a captcha is due) |
| `SecondFactorRequired` | a first factor passed, a second is due | |
| `SignedIn` | signed in (the account, the factors passed, the context) | track the device, send "a new device", bring the account up to date |
| `SignInFailed` | any failure (the factor, the failure, the identifier, the address - never the secret) | count, log |
| `AccountRefused` | the proof was good, the account may not sign in | send "welcome back" to a disabled account |
| `Revoking` | before a factor is turned off | `refuse($reason)`: a role that requires it keeps it |
| `CredentialAdded`, `CredentialRemoved` | a factor set up (a password changed) or turned off | notify |

A refusal ends the attempt with `Failure::Refused` and the listener's reason in `Result::$reason`.

### What each event carries

| Event | Properties (read-only) | Dispatched by |
|---|---|---|
| `Beginning` | `factor`, `context`, `attempt`, `identifier` (first factors) | `begin()`, after the throttle |
| `Verifying` | `factor`, `context`, `attempt`, `identifier` (first factors) | `verify()`, after the throttle |
| `SecondFactorRequired` | `account`, `passed`, `offered`, `context` | `verify()` |
| `SignedIn` | `account`, `passed`, `context` | `verify()` |
| `SignInFailed` | `factor`, `failure`, `context`, `identifier`, `account` (when known), `reason` | every failure of `verify()`; `begin()` only when throttled |
| `AccountRefused` | `account`, `reason`, `factor`, `context` | `verify()`, before its `SignInFailed` |
| `Revoking` | `account`, `factor` | `revoke()` |
| `CredentialAdded` | `account`, `factor` | an enrolment confirmed (a new password included) |
| `CredentialRemoved` | `account`, `factor` | `revoke()` done |

`Beginning`, `Verifying` and `Revoking` have `refuse(string $reason)` and `refusal(): ?string`;
the last listener to refuse sets the reason. A `begin()` refused by a listener answers
`Challenge::failed(refused, $reason)` and dispatches no `SignInFailed`; an `IdentityUnknown` result
dispatches neither `SignedIn` nor `SignInFailed`. Without a dispatcher (`events: null`) nothing is
dispatched and nothing is refused.

A listener refusing a sign-in from one address, with a dispatcher of a few lines (run in the
[harness](harness.md) on 2026-10-25):

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\Context;
use Omniauth\Event\SignedIn;
use Omniauth\Event\SignInFailed;
use Omniauth\Event\Verifying;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\TestAccount;
use Psr\EventDispatcher\EventDispatcherInterface;

// Any PSR-14 dispatcher; a tiny one here.
$events = new class implements EventDispatcherInterface {
    /** @var array<class-string, list<callable>> */
    public array $listeners = [];

    public function dispatch(object $event): object
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }

        return $event;
    }
};
$events->listeners[Verifying::class][] = static function (Verifying $event): void {
    if ('203.0.113.66' === $event->context->ip) {
        $event->refuse('not-a-human');                 // omnishield's answer, say
    }
};
$events->listeners[SignInFailed::class][] = static fn (SignInFailed $e) => print("failed: {$e->factor->value} {$e->failure->value} {$e->reason} {$e->identifier}\n");
$events->listeners[SignedIn::class][] = static fn (SignedIn $e) => print('signed in: '.$e->account->identifier.' from '.$e->context->ip."\n");

$store = new InMemoryStore(new TestAccount('42', 'ringo', 'ringo@example.org', password_hash('yellow-submarine', PASSWORD_BCRYPT)));
$omniauth = new Omniauth($store, new Methods([new PasswordMethod($store, $store)]), new ArrayState(), events: $events);

$proof = ['identifier' => 'ringo', 'password' => 'yellow-submarine'];
$result = $omniauth->verify('password', new Context(ip: '203.0.113.66'), $proof);
echo $result->failure->value, ': ', $result->reason, "\n";
$omniauth->verify('password', new Context(ip: '203.0.113.7'), $proof);
```

```
failed: password refused not-a-human ringo
refused: not-a-human
signed in: ringo from 203.0.113.7
```
