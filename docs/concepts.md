---
title: Concepts
order: 20
---

# Concepts

A sign-in is an **attempt**: one visitor, the factors passed so far, the second factors still
offered, what a ceremony keeps between its two steps. The **conductor** runs each step against the
**method** of a factor and answers with a **result**; the host keeps the attempt between two
requests (`Contract\State`) and reads the result to answer the visitor. The interfaces the
application and the host fill in are [the contracts](contracts.md).

## The conductor

```php
use Omniauth\Omniauth;

$omniauth = new Omniauth(
    accounts: $accounts,       // Contract\Accounts
    methods: $methods,         // Method\Methods: the methods mounted, one per factor
    state: $state,             // Contract\State: the session, or ArrayState
    policy: null,              // Contract\SecondFactorPolicy; null: new EnrolledSecondFactors($methods)
    throttle: null,            // Contract\Throttle; null: no limit
    events: null,              // PSR-14 EventDispatcherInterface; null: no event
    clock: null,               // PSR-20 ClockInterface; null: SystemClock
    lifetime: Omniauth::LIFETIME,   // seconds an attempt lasts: 600
);
```

| Method | Returns | |
|---|---|---|
| `begin(Factor\|string $factor, Context $context, Proof\|array $proof = [])` | `Method\Challenge` | the first step of a `Challenging` method: send a link or a code, give options, send to a provider |
| `verify(Factor\|string $factor, Context $context, Proof\|array $proof = [])` | `Result` | checks a proof: a first factor starts a new attempt, a second one goes on with the attempt in progress |
| `attempt()` | `?Attempt` | the attempt in progress (the second factor's page reads `offered()`), null when none |
| `abandon()` | `void` | forgets the attempt: cancelled, or signed out |
| `enrol(Factor\|string $factor, object $account)` | `Enrolment` | sets a factor up from the account's settings: `->begin($context, $proof)`, then `->confirm($context, $proof)` |
| `revoke(Factor\|string $factor, object $account)` | `Enrolled` | turns a factor off; the trusted devices fall back with it |
| `methods()` | `Method\Methods` | the methods mounted |

| Constant | Value | |
|---|---|---|
| `Omniauth::ATTEMPT` | `omniauth.attempt` | the `State` key of the sign-in attempt |
| `Omniauth::ENROLMENT` | `omniauth.enrolment.` | the prefix of an enrolment's key, followed by the factor (`omniauth.enrolment.totp`) |
| `Omniauth::LIFETIME` | `600` | seconds after which an attempt is gone (`expired`) |

A factor is given as the enum or as its value (`'email-code'`); a value that is no factor throws
PHP's `\ValueError`, a factor no method was mounted for `Exception\UnknownMethod`
([Errors](errors.md)).

### What verify() does

The same sequence whatever the method - that single sequence is what makes the behaviour the same
under every host:

1. A **first factor** starts a new attempt (keeping what a ceremony kept: a challenge begun before
   it). A **second factor** goes on with the attempt in progress: none, or one older than the
   lifetime, fails with `no-attempt` or `expired`; a factor the attempt did not offer fails with
   `not-offered`.
2. The **throttle**: scope the factor's value (`password`, `email-code`...), key what was typed
   and the visitor's address for a first factor (`ringo@example.org|203.0.113.7`, lower case), the
   account for a second one (`account:<handle>`, whatever the address). Refused: `throttled`, and
   nothing is checked.
3. `Event\Verifying`: a listener may refuse (`refused`, with its reason).
4. The **method** checks the proof and gives a `Method\Verdict`. A provider's identity that no
   account is linked to ends the attempt with `IdentityUnknown`; a refusal keeps the attempt and
   fails with the method's failure (`bad-credentials` when it gave none) and reason.
5. `Accounts::check()`: an account that may not sign in now ends the attempt with
   `account-refused` and its reason (`Event\AccountRefused` is dispatched).
6. The throttle is reset for that scope and key; the factor is marked passed; "remember me" is
   kept when a first factor's proof asked it (`remember`) or the method asked it (the sign-in link).
7. The **second-factor rule** says what remains ([The second-factor rule](second-factor.md)). When
   something does, each ambient method (the trusted device) is tried on the way; if one passes, the
   rule is asked again. Still due: `SecondFactorRequired` with the factors offered, the attempt
   kept, `Event\SecondFactorRequired`.
8. Done: `Authenticated`, the attempt cleared, `Event\SignedIn`; after a second factor whose proof
   asked `trust`, the first ambient method's new cookie value in `Result::$trustDevice`.

Every failure dispatches `Event\SignInFailed` (the factor, the failure, the context, the
identifier typed, the account when known, the reason - never the secret).

`begin()` follows the first steps of the same sequence: a first factor restarts the attempt, a
second one needs the attempt in progress and a factor it offered; the throttle's scope is
`<factor>.begin` (`link.begin`, `email-code.begin`: what sends something); `Event\Beginning` may
refuse; the method's challenge is returned and the attempt saved. A method that is not
`Challenging` has nothing to begin: `LogicException`.

### Enrolment

```php
$enrolment = $omniauth->enrol('email-code', $account);    // LogicException for a method that is not Enrolling
$challenge = $enrolment->begin($context);                 // a code sent, a secret and its address, options to sign, or nothing
$enrolled = $enrolment->confirm($context, ['code' => '481516']);
$enrolled->isDone();                                      // true, or $enrolled->failure and ->reason
$enrolled->secrets;                                       // what to show once (backup codes)
```

An enrolment has an attempt of its own (`omniauth.enrolment.<factor>`), the account identified,
apart from any sign-in. `begin()` starts it over (a code sent again has its own ten minutes) and is
throttled as `<factor>.begin`; `confirm()` is throttled as `<factor>`, keyed by the account. Once
done: the enrolment is forgotten, the throttle reset, `Event\CredentialAdded` dispatched - and a new
password makes every trusted browser fall back to the second factor.

`revoke()` dispatches `Event\Revoking` (a listener may refuse: `Enrolled::failed(Failure::Refused,
$reason)`), turns the factor off, makes the trusted devices fall back unless the factor revoked is
the trusted device itself (which forgets every browser), and dispatches `Event\CredentialRemoved`.
A method that is not `Revocable` (the password, the link, a passkey, an identity provider) throws a
`LogicException`.

## The attempt

`Omniauth\Attempt` is what `State` keeps between two requests, as an array of scalars:

```php
[
    'started' => 1792497600,           // when it began (the clock's timestamp)
    'handle' => '42',                  // the account found by the first factor (Accounts::handle()), or null
    'passed' => ['password'],          // the factors passed, in their order
    'offered' => ['email-code', 'backup-code'],
    'kept' => ['email-code' => 1792498200],   // what ceremonies keep (a deadline, a challenge, the OAuth state)
    'remember' => false,
]
```

It holds the account's handle, never the account: the conductor loads the account again
(`Accounts::byHandle()`) each time it reads the attempt back, and an account gone since ends the
attempt. Anything malformed in the state reads as no attempt.

| Method | |
|---|---|
| `startedAt(): int`, `account(): ?object`, `handle(): ?string` | |
| `passed(): list<Factor>`, `hasPassed(Factor): bool`, `offered(): list<Factor>`, `remembers(): bool` | |
| `keep(Factor\|string $key, scalar\|array\|null $value)` | a ceremony keeps a value until its verification |
| `kept(Factor\|string $key)` | reads it |
| `take(Factor\|string $key)` | reads it and forgets it: a challenge serves once |
| `restart(int $now): Attempt` | a new attempt that keeps only what ceremonies kept |
| `toArray(): array`, `Attempt::fromArray(mixed): ?Attempt` | what `State` keeps |

`identify()`, `pass()`, `offer()` and `remember()` are the conductor's (`@internal`).

## The result

`Omniauth\Result`, read-only:

| Property | Type | |
|---|---|---|
| `status` | `Status` | where the attempt stands |
| `account` | `?object` | the account: when authenticated, when a second factor is required, and when it was refused (`account-refused`) |
| `passed` | `list<Factor>` | the factors passed in this attempt, in their order |
| `offered` | `list<Factor>` | `SecondFactorRequired`: what the second factor's page offers (any one suffices), the backup code last |
| `failure` | `?Failure` | `Failed`: why |
| `reason` | `?string` | a stable word under the failure: the account's (`banned`, `locked`), a listener's, a method's (`state-mismatch`, `replayed`...) |
| `remember` | `bool` | the host sets its own remember-me |
| `trustDevice` | `?string` | the `trusted_device` cookie's new value, to set as it is |
| `identity` | `?PendingIdentity` | `IdentityUnknown`: what a sign-up form fills in |

`isAuthenticated()` and `needsSecondFactor()` read the status; `Result::failed($failure, $reason,
$account)` builds a failure.

`Status` (`Omniauth\Status`, a string enum):

| Case | Value | The host |
|---|---|---|
| `Authenticated` | `authenticated` | renews its session id, keeps the account's handle; sets its remember-me when `remember`, the cookie when `trustDevice` |
| `SecondFactorRequired` | `second-factor-required` | shows the second factor's page with `offered` |
| `IdentityUnknown` | `identity-unknown` | keeps `identity` in its session and shows its sign-up ([Identity providers](oauth.md)) |
| `Failed` | `failed` | translates `failure` (and `reason`) on the page the visitor came from |

`Failure` (`Omniauth\Failure`): stable keys a page translates, never a message.

| Case | Value | When |
|---|---|---|
| `BadCredentials` | `bad-credentials` | no such account, or not that secret: one answer for both; an identity provider's refusals, with their reason |
| `BadCode` | `bad-code` | a code (e-mailed, backup, from an application) that is not the right one |
| `InvalidLink` | `invalid-link` | a sign-in link unknown, already used or past its time |
| `Expired` | `expired` | what the ceremony began with is gone: a code past its time, an attempt older than its lifetime, a challenge already used |
| `Throttled` | `throttled` | too many tries: refused before anything was checked |
| `AccountRefused` | `account-refused` | the proof is good but the account may not sign in now (`reason`: banned, locked...) |
| `Refused` | `refused` | a listener of `Verifying`, `Beginning` or `Revoking` refused (`reason`: its word); a passkey's relying party that does not serve the host |
| `NoAttempt` | `no-attempt` | a second factor with no first one passed before it |
| `NotOffered` | `not-offered` | a second factor the attempt did not offer |
| `NotEnrolled` | `not-enrolled` | a factor this account has not set up; an identity the rule will not link by its address (`link-from-settings`) |

## Factors

`Omniauth\Factor` names what a person proves, once for every method, policy and host: the key of
routes, settings and translations.

| Case | Value | `role()` | Method |
|---|---|---|---|
| `Password` | `password` | first | `PasswordMethod` |
| `Link` | `link` | first | `LinkMethod` |
| `Passkey` | `passkey` | first | omniauth/webauthn's `PasskeyMethod` |
| `OAuth` | `oauth` | first | `OAuthMethod` |
| `Totp` | `totp` | second | omniauth/otp's `TotpMethod` |
| `EmailCode` | `email-code` | second | `EmailCodeMethod` |
| `BackupCode` | `backup-code` | second | `BackupCodeMethod` |
| `TrustedDevice` | `trusted-device` | second | `TrustedDevices` |

`Factor::of(Factor|string)` gives the case (PHP's `\ValueError` for an unknown value);
`role()` gives `Role::First` (it finds the account) or `Role::Second` (the account is known).

## The request and the proof

`Omniauth\Context`, read-only - what the host knows of the request, with no object of its framework:

| Property | Type | Default | |
|---|---|---|---|
| `origin` | `string` | `''` | `https://example.org:8443`: WebAuthn compares it; a provider's return path is completed with it |
| `host` | `string` | `''` | the relying party's id depends on it |
| `ip` | `?string` | `null` | the throttle's key for a first factor |
| `userAgent` | `?string` | `null` | |
| `trustedDevice` | `?string` | `null` | the `trusted_device` cookie as the host read it |
| `firewall` | `string` | `main` | the trusted devices' `fwl` claim (scheb/2fa's firewall name) |

`Omniauth\Proof` wraps what the visitor sent, an array: `['identifier' => ..., 'password' =>
...]`, `['code' => ...]`, `['token' => ...]`, a WebAuthn credential's JSON under `json`, an OAuth
return's `code` and `state`. Two keys mean the same to every method: `remember` (at the first
factor: the host sets its remember-me) and `trust` (at the second: a trusted-device cookie).

| Method | |
|---|---|
| `Proof::of(Proof\|array)` | |
| `string($key): string` | the value as a string; `''` when missing or not a scalar |
| `bool($key): bool` | `filter_var(..., FILTER_VALIDATE_BOOL)`: `'1'`, `'on'`, `'true'` are true |
| `has($key): bool` | set and not null |
| `json(): string` | the `json` key, or every value encoded |
| `all(): array` | |

## What a ceremony gives back

`Method\Challenge`, the answer of `begin()` and of an enrolment's `begin()`:

| `kind` | Built by | `payload` | |
|---|---|---|---|
| `none` (`Challenge::NONE`) | `Challenge::none($data)` | null | nothing to give: the next step follows (`data`: a TOTP secret and its address) |
| `json` (`JSON`) | `Challenge::json($json)` | the JSON | options for a script (WebAuthn's) |
| `redirect` (`REDIRECT`) | `Challenge::redirect($url)` | the address | the provider's authorization address |
| `sent` (`SENT`) | `Challenge::sent($data)` | null | a link or a code went out - or the address has no account: the same answer (`data`: the code's `until`) |
| `failed` (`FAILED`) | `Challenge::failed($failure, $reason)` | null | `failure` and `reason` say why; `hasFailed()` is true |

`Enrolled` (`failure`, `secrets`, `reason`; `isDone()`, `Enrolled::done($secrets)`,
`Enrolled::failed($failure, $reason)`) is what setting a factor up, or turning it off, ended with.

`Message` (`factor`, `secret`, `until`) is what a method asks the host's `Sender` to send: the
link's token or the code as `secret` - never logged, never stored as it is.

`PendingIdentity` (`provider`, `subject`, `email`, `emailVerified`, `name`) is someone a provider
vouched for whom no account is linked to.

## Method interfaces

A method checks a proof within an attempt and touches neither request nor response. Its
interfaces, in `Omniauth\Method\` - what the conductor asks of it:

| Interface | Methods | Meaning |
|---|---|---|
| `Method` | `factor(): Factor`, `verify(Attempt, Proof, Context): Verdict` | every method |
| `Challenging` | `begin(Attempt, Proof, Context): Challenge` | gives something first; keeps what it must remember in the attempt, never in the challenge |
| `SecondFactor` | `enrolled(object $account): bool` | a second factor an account sets up: the rule asks whether this one has |
| `Ambient` | `remember(object $account, Context): string` | a second factor read from the context (the cookie), tried by the conductor itself; gives the cookie's value |
| `Enrolling` | `enrolBegin(Attempt, Proof, Context): Challenge`, `enrolConfirm(Attempt, Proof, Context): Enrolled` | set up from the settings |
| `Revocable` | `revoke(object $account): void` | turned off |

`Method\Verdict` is what a method concluded: `Verdict::passed($account, $remember)`,
`Verdict::refused($failure, $reason)`, `Verdict::expired()`, `Verdict::unknown(PendingIdentity)`;
`hasPassed()`.

`Method\Methods` holds the methods mounted, one per factor (a second one for the same factor
replaces the first): `add()`, `has()`, `get()` (throws `UnknownMethod`), `secondFactors()` (the
`SecondFactor` methods that are not ambient), `ambient()`; iterable and countable. Writing a method
of your own: [Writing a method or a provider](providers.md).
