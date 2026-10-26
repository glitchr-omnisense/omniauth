---
title: The contracts
order: 21
---

# The contracts

omniauth decides who signs in; the application keeps its accounts and their proofs, the host its
request, response, session and pages. Between them, small interfaces in `Omniauth\Contract\`.

## The accounts

`Accounts` - an account is any object of the application, which implements nothing of omniauth:

| Method | What it answers |
|---|---|
| `byIdentifier(string $identifier): ?object` | the account of what the person types (an address, a user name), or null |
| `byHandle(string $handle): ?object` / `handle(object $account): string` | the stable handle (the id; also the passkeys' userHandle): what the attempt keeps between requests |
| `identifier(object $account): string` | what the host knows the account by (Symfony's `getUserIdentifier()`); the trusted-device tokens carry it, and the TOTP address shows it |
| `displayName(object $account): string`, `email(object $account): ?string` | what a message and an authenticator show; `email()` is also what an identity provider's verified address is compared with |
| `check(object $account): void` | may it sign in now? throws `Exception\AccountRefused` with a stable reason (`banned`, `locked`...) |

`check()` is called after the proof passed, never before: an unknown address and a banned account
are told apart only once the password is right.

## The stores of each proof

One store per proof, so a site takes only what it serves:

| Contract | Methods | Kept | omnibase (`Base\Auth\`, step AU4) |
|---|---|---|---|
| `Passwords` | `hash($account): ?string`, `update($account, string $hash)` | the hash, as the hasher wrote it; `update()` after a rehash or a new password | `user.password` |
| `LinkTokens` | `issue($account, string $hash, \DateTimeImmutable $until)`, `consume(string $hash, \DateTimeImmutable $now): ?object` | each link's SHA-256 and deadline; `consume()` gives the account while the link is valid at `$now` and uses it up on the way: it serves once | `userToken` "login-token" |
| `PlainLinkTokens` | `consumePlain(string $value, \DateTimeImmutable $now): ?object` | for the switch only: links kept in clear before omniauth | idem, until the last expires |
| `EmailCodes` | `enabled($account): bool`, `enable()`, `disable()`, `remember($account, string $hash)`, `remembered($account): ?string`, `forget($account)` | on or off, the code's keyed hash | `user.emailAuthEnabled`, `user.emailAuthCode` |
| `BackupCodes` | `hashes($account): list<string>`, `replace($account, list<string> $hashes)` | the codes' `password_hash()` hashes | `user.backupCodes` |
| `TrustedVersion` | `version($account): int`, `revokeAll($account)` | the trusted devices' version; `revokeAll()` raises it | `user.trustedTokenVersion` |
| `TotpSecrets` | `secret($account): ?string`, `enable($account, string $secret)`, `disable($account)` | the base32 secret (read by omniauth/otp; sealed at rest by its `SealedTotpSecrets`) | `user.secret` |
| `Identities` | `owner(string $provider, string $subject): ?object`, `link($account, $provider, $subject, ?string $email, bool $emailVerified)`, `unlink($account, $provider)`, `used($provider, $subject, \DateTimeImmutable $at)`, `providers($account): list<string>` | provider + the person's identifier there (read by `Omniauth\OAuth\OAuthMethod`); one subject of a provider has one account, an account one identity per provider | `userIdentity` (AU5) |
| `Omniauth\WebAuthn\Contract\Passkeys` (omniauth/webauthn) | `of($account)`, `byCredentialId(string $base64)`, `add($account, PasskeyRecord, ?string $label)`, `used(PasskeyRecord)` | the passkeys, as `PasskeyRecord` values | `userPasskey` |

No table, no ORM here: the structure stays the application's, described once, read by every host.
`Omniauth\Testing\InMemoryStore` implements all of them but the passkeys over `TestAccount`
objects ([Testing](testing.md)).

## The host's small duties

| Duty | Contract | Plain PHP and tests | Symfony / Laravel (omnibridge) |
|---|---|---|---|
| Keep the attempt between two requests | `State` (`get`, `set`, `remove`; arrays of scalars) | `ArrayState` (over `$_SESSION`: `ArrayState::session()`) | the session (`SessionState` in each bridge) |
| Limit the tries | `Throttle` (`consume(string $scope, string $key): bool`, `reset(string $scope, string $key)`) | `CacheThrottle` on any PSR-6 pool or PSR-16 cache | `CacheThrottle` on a cache pool (Symfony) or a cache store (Laravel) |
| Send a link or a code | `Sender` (`send(object $account, Message $message)`) | `Testing\RecordingSender` | omnibase's `NotifierSender` |
| CSRF on every POST | `Omnibridge\Contract\Tokens` (omnisense/omnibridge) | your form's own | each bridge's `Tokens` |
| Events | PSR-14, optional | any dispatcher | the host's dispatcher |
| Clock | PSR-20, optional | `SystemClock` | the container's clock |
| The second-factor rule | `SecondFactorPolicy` (`required(object $account, array $passed, Context $context): list<Factor>`) | `EnrolledSecondFactors` | the same, decorated by omnibase's rule |

What stays the host's alone: its firewall or guard, reading the request and writing the response,
the session cookie and its renewal at each sign-in (against session fixation), signing out, the
remember-me, switching user, API tokens, and the pages with their forms.

`ArrayState` keeps a PHP array by reference (`new ArrayState($array)`, or none for its own);
`ArrayState::session()` binds it to `$_SESSION` and throws a `LogicException` when no session is
started; `all()` gives what it holds.

> [!NOTE]
> The `Throttle` contract's docblock names Symfony's `RateLimiterFactory` and Laravel's
> `RateLimiter` as hosts' throttles; both bridges mount omniauth's `CacheThrottle` instead (on a
> cache pool, or a cache store). Either may implement the contract: one with an atomic store is
> exact under concurrent tries, `CacheThrottle` is not ([Configuration](configuration.md#the-throttles-limits)).
