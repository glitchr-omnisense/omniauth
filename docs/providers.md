---
title: Writing a method or a provider
order: 80
---

# Writing a method or a provider

omniauth has two extension points: a **method** (a way to prove a factor, `Method\Method`) and an
**identity provider** (a server the OAuth method sends the visitor to, `OAuth\Provider\Provider`).
The packages omniauth/otp and omniauth/webauthn are methods; omniauth/google is a provider.

## A method

A method checks a proof within an attempt and touches neither request nor response. It implements
`Method\Method` and, as it needs, the other interfaces of [Methods](concepts.md#method-interfaces):

```php
use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\SecondFactor;
use Omniauth\Method\Verdict;
use Omniauth\Proof;

/** A code read from a hardware token, checked by the application's own service. */
final class HardwareTokenMethod implements SecondFactor
{
    public function __construct(private readonly TokenChecker $checker) {}

    public function factor(): Factor
    {
        return Factor::Totp;              // the factors are omniauth's enum: a method stands for one of them
    }

    public function enrolled(object $account): bool
    {
        return $this->checker->hasToken($account);
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = $attempt->account();   // a second factor: the account the first factor found
        if (null === $account) {
            return Verdict::refused(Failure::NoAttempt);
        }

        return $this->checker->check($account, $proof->string('code'))
            ? Verdict::passed($account)
            : Verdict::refused(Failure::BadCode);
    }
}
```

The rules every method of the core keeps:

- **The factor is one of `Factor`'s cases.** `Methods` holds one method per factor: a method of
  your own replaces the core's for that factor. `Factor::role()` decides whether it is a first
  factor (it finds the account: `Verdict::passed($account)` with the account it found) or a
  second one (the account is `$attempt->account()`).
- **What a ceremony remembers goes into the attempt**, never into the answer:
  `$attempt->keep($factor, $value)` at `begin()`, `$attempt->take($factor)` at `verify()` (a
  challenge serves once), values of scalars and arrays (the attempt is serialised into the
  session). Nothing kept at verification time is `Verdict::expired()`.
- **One answer for "no such account" and "wrong secret"**: `bad-credentials` for a first factor,
  in the same time when the method can (the password checks a hash of nothing).
- **Stable failures, stable reasons**: a `Failure` case, and a short reason a page translates
  (`replayed`, `invalid-assertion`); a library's message is for the log.
- **No request, no response**: what the request says comes in the `Context`, what the visitor
  sent in the `Proof`; what the host must do comes out in the `Challenge` or the `Verdict`.
- The conductor does the rest - throttle, events, `Accounts::check()`, the second-factor rule,
  `SignedIn`: a method does not dispatch, throttle or check the account.

A method to set up from the settings implements `Enrolling` (`enrolBegin()`, `enrolConfirm()`, the
enrolment being an `Attempt` with its account identified); one to turn off, `Revocable`. A second
factor the rule must know about implements `SecondFactor::enrolled()`. A second factor read from the
request rather than typed implements `Ambient` (the conductor tries it by itself and asks it for the
cookie's value after `trust`).

Test a method through the conductor, on the in-memory stores and a `FixedClock`
([Testing](testing.md)).

## An identity provider

A provider gives the authorization address of a trip and, for the code that came back, the
`Identity` it vouches for:

```php
use Omniauth\OAuth\Authorization;
use Omniauth\OAuth\Exception\ProviderError;
use Omniauth\OAuth\Identity;
use Omniauth\OAuth\Provider\Provider;

interface Provider
{
    public function name(): string;                                           // its key: the routes' {provider}, Identities' $provider
    public function authorizationUrl(Authorization $authorization): string;   // carries the state, the S256 challenge, the nonce
    /** @throws ProviderError */
    public function identify(string $code, Authorization $authorization): Identity;
}
```

What omniauth gives the provider, `OAuth\Authorization`:

| Member | |
|---|---|
| `state`, `verifier`, `nonce` | 32 random bytes each, base64url (43 characters); the verifier never leaves the server |
| `origin` | the scheme and host of the request that began the trip (`https://example.org:8443`), or null |
| `challenge()` | the verifier's S256 challenge, `BASE64URL(SHA-256(verifier))` (RFC 7636 §4.2) |
| `returnAddress(string $configured)` | an absolute address as it is; a path on the trip's origin (`LogicException` without one) |
| `matches(string $state)` | constant-time comparison (the method's, before anything else) |
| `Authorization::fresh(?string $origin)`, `toArray()`, `fromArray()`, `Authorization::base64url()` | |

What the provider gives back, `OAuth\Identity`: `provider` (its own name - the method refuses an
identity of another), `subject` (not empty: `InvalidArgumentException`), `email`, `emailVerified`
(true only when the provider says so: `Identity::verified($claim)` reads a JSON `true`, `"true"`, `1`
or `"1"`), `name`, and every claim as the provider said it (`claims`).

The rules:

- **The return address is the configuration's**, never the request's: absolute, or a path completed
  by `Authorization::returnAddress()`; the same address is sent again at the exchange.
- **PKCE and the state are omniauth's**: the address carries `state` and `code_challenge` with
  `code_challenge_method=S256`; the exchange sends `code_verifier`. An OpenID Connect provider sends
  the `nonce` and checks it in the ID token.
- **Everything the server refuses or fails to answer is a `ProviderError`** with one of its four
  reasons - never another exception, never the client's secret in its message.
- Over league/oauth2-client, extend `OAuth\Provider\LeagueProvider` and override what differs
  ([Over league/oauth2-client](oauth.md#over-leagueoauth2-client)): omniauth/google adds the nonce
  in `authorizationOptions()` and reads the ID token in `identity()`.

A provider made from a host's configuration comes with a factory:

```php
use Omniauth\OAuth\Provider\Provider;
use Omniauth\OAuth\Provider\ProviderFactory;

final class ForgeFactory implements ProviderFactory
{
    public function create(string $name, array $entry): ?Provider
    {
        if ('forge' !== ($entry['type'] ?? $name)) {
            return null;                  // not this factory's kind: the next factory is asked
        }

        return new ForgeProvider($name, (string) $entry['client_id'], (string) ($entry['client_secret'] ?? ''), (string) $entry['redirect_uri']);
    }
}
```

`Providers::configured()` asks each factory in turn and has already left out the entries without a
`client_id` and refused those without a `redirect_uri`. Under Symfony a factory class of the
application is autoconfigured (tag `omniauth.oauth_provider_factory`); under Laravel, tag its binding
`omniauth.oauth_provider_factory`.

## Its conformance suite

A provider package runs `Omniauth\Testing\ProviderContractTests` on recorded answers, in a
`Tests/<Name>ContractTest.php`: [Conformance suite](oauth.md#conformance-suite) lists what each
test checks and the hooks to fill (`provider()`, `consented()`, `refused()`, `unanswered()`,
`factory()`, `entry()`, `secret()`, `sendsNonce()`). The trait requires phpunit/phpunit (omniauth
suggests it). Methods have no shared suite: each package's tests drive its method through the
conductor.
