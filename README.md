# omnisense/omniauth

Who signs in, and by which factors, **under any PHP host**: the methods and their ceremonies
(begin, then verify), the second-factor rule, and the result of an attempt - who, what passed,
what remains. The host keeps its firewall or guard, the request and the response, its session
cookie and its pages; the application keeps its accounts and their proofs in its own tables.

```php
use Omniauth\{ArrayState, CacheThrottle, Context, Omniauth};
use Omniauth\Method\Methods;
use Omniauth\Password\PasswordMethod;
use Omniauth\EmailCode\EmailCodeMethod;
use Omniauth\BackupCode\BackupCodeMethod;
use Omniauth\TrustedDevice\TrustedDevices;

$omniauth = new Omniauth(
    accounts: $accounts,                                  // your accounts, behind Contract\Accounts
    methods: new Methods([
        new PasswordMethod($accounts, $passwords),        // symfony/password-hasher: Symfony's hashes, >72 bytes included
        new EmailCodeMethod($accounts, $emailCodes, $sender, $secret),
        new BackupCodeMethod($backupCodes),
        new TrustedDevices($accounts, $trustedVersion, $secret),   // scheb/2fa's trusted_device cookie
    ]),
    state: new ArrayState($_SESSION),                     // the session, under a framework
    throttle: new CacheThrottle($pool),                   // any PSR-6 pool or PSR-16 cache
);
$context = new Context(origin: 'https://example.org', host: 'example.org', ip: $_SERVER['REMOTE_ADDR'], trustedDevice: $_COOKIE['trusted_device'] ?? null);

$result = $omniauth->verify('password', $context, ['identifier' => 'ringo@example.org', 'password' => '...']);
// SecondFactorRequired, $result->offered === [Factor::EmailCode, Factor::BackupCode]
$omniauth->begin('email-code', $context);                 // the code goes out through your Sender
$result = $omniauth->verify('email-code', $context, ['code' => '481516', 'trust' => true]);
// Authenticated, $result->passed === [Factor::Password, Factor::EmailCode], $result->trustDevice === 'eyJ0eXAiOiJKV1Qi...'
```

It requires PHP 8.2, four PSR interfaces (cache, simple-cache, clock, event-dispatcher) and
`symfony/password-hasher`, a standalone component. The sign-in through identity providers is here
too (`Omniauth\OAuth\`), with no OAuth library: each provider is a package of its own, as
omnishield has one per captcha. The methods that carry a library are packages of their own; the
hosts are bridged in omnibridge:

| Package | What |
|---|---|
| `omnisense/omniauth` (here) | the contracts, the conductor, the methods that need no library: password, sign-in link, code by e-mail, backup codes, trusted devices; the sign-in through identity providers (`Omniauth\OAuth\`: the state, PKCE and nonce, the linking by `sub`, the sign-up of an unknown identity; `LeagueProvider` for any league/oauth2-client provider, league suggested); events; `Testing\` |
| [`omniauth/webauthn`](https://github.com/glitchr-omnisense/omniauth-webauthn) | passkeys, over web-auth/webauthn-lib; compatible both ways with web-auth/webauthn-symfony-bundle's records (formerly omniauth/passkey) |
| [`omniauth/otp`](https://github.com/glitchr-omnisense/omniauth-otp) | authenticator applications (TOTP), over spomky-labs/otphp, on scheb/2fa's parameters; a code once; the secrets sealed at rest with sodium (formerly omniauth/totp) |
| [`omniauth/google`](https://github.com/glitchr-omnisense/omniauth-google) | Google, over league/oauth2-google: one package per provider |
| omniauth/oauth | **abandoned**: its mechanics are this package's `Omniauth\OAuth\` since 2026-10-23 (same namespace, same class names); its last release is empty and requires this one |
| omnibridge/symfony, omnibridge/laravel | an authenticator per firewall; a guard and a user provider |

The name: OmniAuth is also Ruby's well-known sign-in library. This one is PHP, under `omnisense/`,
and unrelated.

## The shape

| | |
|---|---|
| `Omniauth` | the conductor: `begin()`, `verify()`, `attempt()`, `abandon()`, `enrol()`, `revoke()` - one sequence whatever the method: throttle, `Verifying`, the method, `Accounts::check()`, the second-factor rule, `SignedIn` or `SignInFailed` |
| `Attempt`, `Result`, `Status`, `Failure`, `Factor`, `Context`, `Proof`, `Enrolment`, `Enrolled`, `Message`, `PendingIdentity` | the attempt kept between two requests, what it ended with, the stable keys a page translates |
| `Contract\` | `Accounts`; one store per proof (`Passwords`, `LinkTokens`, `EmailCodes`, `BackupCodes`, `TrustedVersion`, `TotpSecrets`, `Identities`); `SecondFactorPolicy`; the host's duties (`State`, `Throttle`, `Sender`) |
| `Method\` | `Method` (verify), `Challenging` (begin first), `SecondFactor` (enrolled?), `Ambient` (read from the context), `Enrolling`, `Revocable`; `Verdict`, `Challenge`, `Methods` |
| `Password\`, `Link\`, `EmailCode\`, `BackupCode\`, `TrustedDevice\` | the five methods of the core |
| `OAuth\` | `OAuthMethod` (a first factor: the trip out and back, the linking rule, `adopt()`), `Authorization`, `Identity`, `Provider\{Provider, ProviderFactory, Providers, LeagueProvider}`, `NoSecondFactorLinking`, `Testing\FakeProvider` |
| `EnrolledSecondFactors`, `ArrayState`, `CacheThrottle`, `SystemClock` | the defaults: the rule, the state, the throttle, the clock |
| `Event\` | PSR-14: `Beginning`, `Verifying`, `SecondFactorRequired`, `SignedIn`, `SignInFailed`, `AccountRefused`, `Revoking`, `CredentialAdded`, `CredentialRemoved` |
| `Testing\` | in-memory stores, a recording sender, a fixed clock, an array cache, stand-ins for a passkey and an authenticator application |

## Decisions it carries (Marco, 2026-10-20)

- **No second factor after a passkey** (QA1), **a second factor after an identity provider** (QA2):
  `EnrolledSecondFactors::AFTER` is `[Password, Link, OAuth]`.
- **The sign-in link**: 15 minutes, once, its SHA-256 kept, never its value; a new link at each
  request, 3 an hour (QA4).
- **The code by e-mail kept hashed**: an HMAC keyed by the host's secret, bound to the account (QA5).
- **The bridges in omnibridge**, omniauth only suggested there (QA8).

## Documentation

The manual, in `docs/` (one page on the site, in this order):

- [Overview](docs/index.md): the methods and the identity providers
- [Installation](docs/installation.md): requirements, a whole sign-in in plain PHP
- [Symfony and Laravel](docs/hosts.md), [Symfony](docs/symfony.md), [Laravel](docs/laravel.md)
- [Concepts](docs/concepts.md), [The contracts](docs/contracts.md)
- [Password](docs/password.md), [sign-in link](docs/link.md), [code by e-mail](docs/email-code.md),
  [backup codes](docs/backup-codes.md), [trusted devices](docs/trusted-devices.md),
  [identity providers](docs/oauth.md)
- [The second-factor rule](docs/second-factor.md), [Events](docs/events.md)
- [Configuration reference](docs/configuration.md), [Errors](docs/errors.md)
- [Testing](docs/testing.md), [The Docker harness](docs/harness.md)
- [Writing a method or a provider](docs/providers.md), [Verified, and not](docs/verified.md)

## Verified, and not

- Verified (`docker compose run --rm omniauth test` in `docker/`, PHP 8.4; every file linted on
  PHP 8.2): each method's way through and its refusals on the in-memory stores - wrong secret,
  unknown address answered as a wrong password, expired, used twice, throttled, a banned account,
  the second factor offered, passed, not offered, its attempt expired; QA1 and QA2 through the
  default rule; the trusted-device tokens equal to those scheb/2fa 8.6.1's `JwtTokenEncoder`
  writes for the same input, whole seconds and microseconds, each read by the other (recorded, and
  live with scheb/2fa installed in the harness); hashes written by symfony/password-hasher 8.1
  ("auto": bcrypt, cost 13), one of a 102-byte password that `password_verify()` alone refuses;
  `docs/installation.md` and `docs/oauth.md` run as written; in plain PHP, a whole sign-in by hand
  loading nothing but omniauth, the PSR interfaces and symfony/password-hasher, a sign-in through a
  fake identity provider loading no OAuth library, and every class of the package loading with them
  alone, `LeagueProvider` included (`BareTest`); the identity providers' trip (state, PKCE, nonce,
  forged and injected returns, the linking rule, a return path on the host of each trip) on the fake
  provider, and `LeagueProvider` over a generic league provider with recorded answers.
- The providers' conformance suite (`Omniauth\Testing\ProviderContractTests`,
  [docs/oauth.md](docs/oauth.md#conformance-suite)): green on 2026-10-25 for `FakeProvider` and
  omniauth/google, on recorded answers (the workspace, 235 tests); no defect found.
- Not verified: an application's own stores (omnibase fills them at step AU4); a real host (the
  bridges come with AU6 and AU8); a cookie written by a running scheb/2fa on an application (the
  encoder is compared, not a browser's cookie); concurrent tries against `CacheThrottle` (read then
  written, not atomic: a host that needs it exact uses its rate limiter).

## Licence

MIT.
