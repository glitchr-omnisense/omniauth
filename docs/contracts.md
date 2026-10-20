---
title: The contracts
order: 2
---

# The contracts

omniauth decides who signs in; the application keeps its accounts and their proofs, the host its
request, response, session and pages. Between them, small interfaces in `Omniauth\Contract\`.

## The accounts

`Accounts` - an account is any object of the application, which implements nothing of omniauth:

| Method | What it answers |
|---|---|
| `byIdentifier($identifier)` | the account of what the person types (an address, a user name), or null |
| `byHandle($handle)` / `handle($account)` | the stable handle (the id; also the passkeys' userHandle): what the attempt keeps between requests |
| `identifier($account)` | what the host knows the account by (Symfony's `getUserIdentifier()`); the trusted-device tokens carry it |
| `displayName($account)`, `email($account)` | what a message and an authenticator show |
| `check($account)` | may it sign in now? throws `Exception\AccountRefused` with a stable reason (`banned`, `locked`...) |

## The stores of each proof

One store per proof, so a site takes only what it serves:

| Contract | Kept | omnibase (`Base\Auth\`, step AU4) |
|---|---|---|
| `Passwords` | the hash, as the hasher wrote it; `update()` after a rehash or a new password | `user.password` |
| `LinkTokens` | each link's SHA-256 and deadline; `consume()` uses it up | `userToken` "login-token" |
| `PlainLinkTokens` | for the switch only: links kept in clear before omniauth | idem, until the last expires |
| `EmailCodes` | on or off, the code's keyed hash | `user.emailAuthEnabled`, `user.emailAuthCode` |
| `BackupCodes` | the codes' `password_hash()` hashes | `user.backupCodes` |
| `TrustedVersion` | the trusted devices' version; `revokeAll()` raises it | `user.trustedTokenVersion` |
| `TotpSecrets` | the base32 secret (read by omniauth/totp) | `user.secret` |
| `Identities` | provider + the person's identifier there (read by omniauth/oauth) | `userIdentity` (AU5) |

No table, no ORM here: the structure stays the application's, described once, read by every host.

## The host's small duties

| Duty | Contract | Plain PHP and tests | Symfony / Laravel (omnibridge, AU6 / AU8) |
|---|---|---|---|
| Keep the attempt between two requests | `State` (`get`, `set`, `remove`; arrays of scalars) | `ArrayState` (over `$_SESSION`: `ArrayState::session()`) | the session |
| Limit the tries | `Throttle` (`consume($scope, $key)`, `reset()`) | `CacheThrottle` on any PSR-6 pool or PSR-16 cache | `RateLimiterFactory` / `RateLimiter` |
| Send a link or a code | `Sender` (`send($account, Message)`) | `Testing\RecordingSender` | omnibase's Notifier |
| CSRF on every POST | `Omnibridge\Contract\Tokens` (omniscient/omnibridge) | your form's own | `CsrfTokens` / `SessionTokens` |
| Events | PSR-14, optional | any dispatcher | the host's dispatcher |
| Clock | PSR-20, optional | `SystemClock` | `Omnibridge\Runtime` |

What stays the host's alone: its firewall or guard, reading the request and writing the response,
the session cookie and its renewal at each sign-in (against session fixation), signing out, the
remember-me, switching user, API tokens, and the pages with their forms.

## The conductor

```php
$omniauth->begin($factor, $context, $proof = []);    // Method\Challenge: json, redirect, sent, none, failed
$omniauth->verify($factor, $context, $proof = []);   // Result: Authenticated, SecondFactorRequired, IdentityUnknown, Failed
$omniauth->attempt();                                // the attempt in progress (Attempt::offered()), or null
$omniauth->abandon();                                // forget it (cancelled, signed out)
$omniauth->enrol($factor, $account)->begin($context) / ->confirm($context, $proof);   // Enrolled
$omniauth->revoke($factor, $account);                // Enrolled; the trusted devices fall back
```

`verify()` does the same whatever the method: the throttle (scope: the factor; key: what was typed
and the address for a first factor, the account for a second one), `Event\Verifying` (a listener
may refuse), the method, `Accounts::check()`, the second-factor rule (an ambient factor - the
trusted device - tried on the way), `SignedIn` or `SignInFailed`, the attempt kept or cleared. A
first factor starts a new attempt; a second one goes on with it, and only with a factor it offered.
An attempt lasts 10 minutes.

`Context` is what the host knows of the request: `origin`, `host`, `ip`, `userAgent`, the
`trustedDevice` cookie, the `firewall` name. `Proof` is what the visitor sent, as an array; two
keys mean the same to every method: `remember` (at the first factor) and `trust` (at the second).
