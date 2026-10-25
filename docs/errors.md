---
title: Errors
order: 50
---

# Errors

A visitor's mistake is never an exception: a wrong password, an expired code, a forged return
from a provider, too many tries all come back as a `Result` (or a `Challenge`, an `Enrolled`) with
a `Failure` and, often, a reason ([Concepts](concepts.md#the-result)). Exceptions are the host's
and the application's mistakes, and the one refusal a store throws on purpose.

## The exceptions

Every exception class of omniauth implements `Omniauth\Exception\OmniauthException` (a
`\Throwable`):

| Exception | Extends | Thrown |
|---|---|---|
| `Exception\AccountRefused` | `\RuntimeException` | by the application's `Accounts::check()`: the account may not sign in now. `$reason` (`banned`, `locked`...) is the stable word; the conductor catches it and answers `account-refused` with that reason. Message: `The account may not sign in: banned.` |
| `Exception\UnknownMethod` | `\InvalidArgumentException` | `Methods::get()` (so `begin()`, `verify()`, `enrol()`, `revoke()`) for a factor no method was mounted for. Message: `No method is mounted for "totp"; mounted: password.` |
| `OAuth\Exception\ProviderError` | `\RuntimeException` | by a provider (`Provider::identify()`): it vouched for nobody. `$reason`: `exchange-failed` (`ProviderError::EXCHANGE`), `invalid-id-token` (`ID_TOKEN`), `no-subject` (`SUBJECT`), `hosted-domain` (`HOSTED_DOMAIN`). `OAuthMethod` catches it and answers `bad-credentials` with that reason; the message (and the previous exception, league's or Guzzle's) is for the server's log, never the page |
| `Omniauth\Otp\Exception\UnreadableSecret` (omniauth/otp) | `\RuntimeException` | a sealed TOTP secret that does not open (another key, an altered row): not caught by the method - a sign-in with that factor fails loudly rather than letting the account in without it |

## Programming errors

Not `OmniauthException`s - a host or an application calling omniauth wrongly:

| Exception | When |
|---|---|
| `\ValueError` | a factor given as a string that is no `Factor` value (`verify('sms', ...)`) |
| `\LogicException` | `begin()` of a method that has nothing to begin (`The "password" method has nothing to begin: verify() it directly.`); `enrol()` of one that is not set up by an account; `revoke()` of one that cannot be turned off (`The "password" method cannot be turned off.`); `ArrayState::session()` before `session_start()`; a provider's return path with no origin known (`Context::$origin` empty at the beginning); a league provider that draws its own PKCE verifier; a provider vouching for an identity of another provider's name; `Providers::fromConfig()` without omniauth/google |
| `\InvalidArgumentException` | `EmailCodeMethod` without a secret or with digits outside 4 to 9; `TrustedDevices` with a secret under 32 bytes; `Providers::configured()` given an entry without `redirect_uri`, or one no factory makes; an `Identity` without a subject; the test doubles given the wrong objects |

These come out of `Providers::configured()` and the conductor as they are (run in the
[harness](harness.md) on 2026-10-25):

```
Omniauth\Exception\UnknownMethod: No method is mounted for "totp"; mounted: password.
ValueError: "sms" is not a valid backing value for enum Omniauth\Factor
LogicException: The "password" method has nothing to begin: verify() it directly.
LogicException: The "password" method cannot be turned off.
InvalidArgumentException: The "google" provider has no redirect_uri: its return address (or path) is its configuration's.
InvalidArgumentException: No provider factory makes "github" (type "github"): install its package (omniauth/github) and pass its factory.
```

and an account the application refuses, through the conductor:

```php
$store = new InMemoryStore(new TestAccount('7', 'pete', 'pete@example.org', password_hash('x', PASSWORD_BCRYPT), refused: 'banned'));
$omniauth = new Omniauth($store, new Methods([new PasswordMethod($store, $store)]), new ArrayState());
$result = $omniauth->verify('password', new Context(ip: '203.0.113.7'), ['identifier' => 'pete', 'password' => 'x']);
echo $result->status->value, ' ', $result->failure->value, ' ', $result->reason, ' ', $result->account?->identifier, "\n";
// failed account-refused banned pete
```

## The failures and their reasons

What a page translates - `Result::$failure`, and `Result::$reason` under it:

| Failure | Reasons | Given by |
|---|---|---|
| `bad-credentials` | - | the password; a stub |
| | `unknown-provider`, `state-mismatch`, `provider-denied`, `provider-error`, `missing-code`, `exchange-failed`, `invalid-id-token`, `no-subject`, `hosted-domain`, `linked-elsewhere`, `other-identity` | an identity provider ([Identity providers](oauth.md#refusals)) |
| | `unreadable-credential`, `unknown-passkey`, `invalid-assertion`, `invalid-attestation`, `known-passkey` | omniauth/webauthn |
| `bad-code` | - | an e-mailed code, a backup code, a TOTP code that is not the right one |
| | `replayed`, `unreadable-secret` | omniauth/otp: a code already used (or older than the last used); a secret that is not base32 |
| `invalid-link` | - | a sign-in link unknown, used or past its time |
| `expired` | - | a code past its time; an attempt older than its lifetime; a ceremony's challenge already used or never begun |
| `throttled` | - | the throttle, before anything was checked |
| `account-refused` | the account's (`banned`, `locked`...) | `Accounts::check()` |
| `refused` | a listener's | `Verifying`, `Beginning`, `Revoking` |
| | `relying-party` | omniauth/webauthn: the host is not under the relying party's id |
| | `csrf` | the bridges: a form's token refused (not omniauth's own) |
| `no-attempt` | - | a second factor with no first one passed |
| `not-offered` | - | a second factor the attempt did not offer |
| `not-enrolled` | - | a factor the account has not set up (TOTP without a secret, an untrusted browser) |
| | `link-from-settings` | an identity provider: an account the rule will not link by its address |

## What to catch where

| Where | Catch |
|---|---|
| the sign-in pages | nothing: read the `Result`; translate `failure` and `reason`, never show a provider's message |
| a store's `check()` | throw `AccountRefused` with a stable reason the page translates |
| a provider of your own | throw `ProviderError` with one of its reasons for anything the server answered or failed to answer; let programming errors through |
| booting the container, a deploy | `\InvalidArgumentException`, `\LogicException`: a configuration to fix, not to retry |
| a sign-in with omniauth/otp's sealed secrets | `UnreadableSecret`: a key missing or wrong on this host - an alert, not a visitor's error |
