# omniscient/omniauth

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
`symfony/password-hasher`, a standalone component. The methods that carry a library are packages
of their own; the hosts are bridged in omnibridge:

| Package | What |
|---|---|
| `omniscient/omniauth` (here) | the contracts, the conductor, the methods that need no library: password, sign-in link, code by e-mail, backup codes, trusted devices; events; `Testing\` |
| `omniauth/passkey` (to come) | passkeys, over web-auth/webauthn-lib |
| `omniauth/totp` (to come) | authenticator applications, over spomky-labs/otphp (scheb/2fa's) |
| `omniauth/oauth` (to come) | identity providers (Google first), over league/oauth2-client |
| omnibridge/symfony, omnibridge/laravel (to come) | an authenticator per firewall; a guard and a user provider |

The name: OmniAuth is also Ruby's well-known sign-in library. This one is PHP, under `omniscient/`,
and unrelated.

## The shape

| | |
|---|---|
| `Omniauth` | the conductor: `begin()`, `verify()`, `attempt()`, `abandon()`, `enrol()`, `revoke()` - one sequence whatever the method: throttle, `Verifying`, the method, `Accounts::check()`, the second-factor rule, `SignedIn` or `SignInFailed` |
| `Attempt`, `Result`, `Status`, `Failure`, `Factor`, `Context`, `Proof`, `Enrolment`, `Enrolled`, `Message`, `PendingIdentity` | the attempt kept between two requests, what it ended with, the stable keys a page translates |
| `Contract\` | `Accounts`; one store per proof (`Passwords`, `LinkTokens`, `EmailCodes`, `BackupCodes`, `TrustedVersion`, `TotpSecrets`, `Identities`); `SecondFactorPolicy`; the host's duties (`State`, `Throttle`, `Sender`) |
| `Method\` | `Method` (verify), `Challenging` (begin first), `SecondFactor` (enrolled?), `Ambient` (read from the context), `Enrolling`, `Revocable`; `Verdict`, `Challenge`, `Methods` |
| `Password\`, `Link\`, `EmailCode\`, `BackupCode\`, `TrustedDevice\` | the five methods of the core |
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

- [Installation, in plain PHP](docs/installation.md)
- [The contracts](docs/contracts.md)
- [Password](docs/password.md), [sign-in link](docs/link.md), [code by e-mail](docs/email-code.md),
  [backup codes](docs/backup-codes.md), [trusted devices](docs/trusted-devices.md)
- [The second-factor rule](docs/second-factor.md)
- [Events](docs/events.md)
- [Testing](docs/testing.md)
- [Symfony and Laravel](docs/hosts.md)
- [The Docker harness](docs/harness.md)

## Verified, and not

- Verified (`docker compose run --rm omniauth test` in `docker/`, PHP 8.4; every file linted on
  PHP 8.2): each method's way through and its refusals on the in-memory stores - wrong secret,
  unknown address answered as a wrong password, expired, used twice, throttled, a banned account,
  the second factor offered, passed, not offered, its attempt expired; QA1 and QA2 through the
  default rule; the trusted-device tokens equal to those scheb/2fa 8.6.1's `JwtTokenEncoder`
  writes for the same input, whole seconds and microseconds, each read by the other (recorded, and
  live with scheb/2fa installed in the harness); hashes written by symfony/password-hasher 8.1
  ("auto": bcrypt, cost 13), one of a 102-byte password that `password_verify()` alone refuses;
  `docs/installation.md` run as written; in plain PHP, a whole sign-in by hand loading nothing but
  omniauth, the PSR interfaces and symfony/password-hasher, and every class of the package loading
  with them alone (`BareTest`).
- Not verified: an application's own stores (omnibase fills them at step AU4); a real host (the
  bridges come with AU6 and AU8); a cookie written by a running scheb/2fa on an application (the
  encoder is compared, not a browser's cookie); concurrent tries against `CacheThrottle` (read then
  written, not atomic: a host that needs it exact uses its rate limiter).

## Licence

MIT.
