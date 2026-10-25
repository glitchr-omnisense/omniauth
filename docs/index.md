# omniscient/omniauth

Omniauth decides who signs in, and by which factors, under any PHP host. A sign-in is a ceremony
of one or two steps - `begin()` (send a link or a code, give a passkey's options, send the visitor
to an identity provider), then `verify()` (check what came back) - and every way in goes through
the same conductor, `Omniauth\Omniauth`, which runs the same sequence whatever the method: the
throttle, an event a listener may refuse on, the method, the account's own check, the second-factor
rule, the attempt kept between two requests or cleared. It answers with a `Result`: who, by which
factors, what remains to pass, and what the host does next (open its session, set its remember-me,
set the trusted-device cookie).

```php
$omniauth->verify('password', $context, ['identifier' => 'ringo@example.org', 'password' => '...']);
// Result: SecondFactorRequired, offered [email-code, backup-code]
$omniauth->begin('email-code', $context);                 // the code goes out through the host's Sender
$omniauth->verify('email-code', $context, ['code' => '481516', 'trust' => true]);
// Result: Authenticated, passed [password, email-code], the trusted_device cookie's value
```

The host keeps what is its own: the firewall or the guard, the request and the response, the
session cookie and its renewal at each sign-in, signing out, the remember-me, the pages and their
forms. The application keeps its accounts and their proofs in its own tables, behind small store
contracts ([The contracts](contracts.md)): omniauth has no table, no ORM and no framework. The
core requires PHP 8.2, four PSR interfaces (cache, simple-cache, clock, event-dispatcher) and
`symfony/password-hasher`, a component that stands alone. The Symfony and Laravel bridges live in
omnibridge (`omnibridge/symfony`, `omnibridge/laravel`), where omniauth is only suggested
([Symfony](symfony.md), [Laravel](laravel.md)).

The name: OmniAuth is also Ruby's well-known sign-in library. This one is PHP, published under
`omniscient/`, and unrelated.

### When to use it

- A site that signs people in by more than a password - a sign-in link, a code by e-mail, an
  authenticator application, backup codes, passkeys, Google - and wants one rule for the second
  factor whatever the first one was.
- A site served by two hosts over one database (a Symfony site and its Laravel twin): the same
  hashes, the same trusted-device cookie (scheb/2fa's format), the same TOTP secrets and the same
  route names under both.
- Moving off scheb/2fa, web-auth/webauthn-symfony-bundle or hwi/oauth-bundle without losing the
  browsers already trusted, the passkeys already registered or the secrets already kept: the
  formats are theirs, byte for byte where it matters.

It is not an identity server (it issues no tokens to other applications), not a user manager (it
creates no account: an unknown identity comes back as `IdentityUnknown` for the host's sign-up
page), and not an API authentication layer (API tokens, impersonation and switching user stay the
host's).

### Methods

One method per factor; a host mounts the ones it serves (`Method\Methods`). "begin" is a first
step before the verification (`Method\Challenging`); "set up" a ceremony from the account's
settings (`Method\Enrolling`, `$omniauth->enrol()`); "turn off" `$omniauth->revoke()`
(`Method\Revocable`). Read from each class's interfaces.

| Factor (`Factor::`) | Class | Package | Role | begin | Set up | Turn off |
|---|---|---|---|---|---|---|
| `password` (`Password`) | `Password\PasswordMethod` | `omniscient/omniauth` | first | - | yes: a new password | - |
| `link` (`Link`) | `Link\LinkMethod` | `omniscient/omniauth` | first | yes: sends the link | - | - |
| `oauth` (`OAuth`) | `OAuth\OAuthMethod` | `omniscient/omniauth` | first | yes: the provider's address | yes: links a provider | - (the store's `Identities::unlink()`) |
| `passkey` (`Passkey`) | `Omniauth\WebAuthn\PasskeyMethod` | [`omniauth/webauthn`](https://github.com/glitchr-studio/omniauth-webauthn) | first | yes: the request options | yes: registers a passkey | - |
| `email-code` (`EmailCode`) | `EmailCode\EmailCodeMethod` | `omniscient/omniauth` | second | yes: sends the code | yes | yes |
| `totp` (`Totp`) | `Omniauth\Otp\TotpMethod` | [`omniauth/otp`](https://github.com/glitchr-studio/omniauth-otp) | second | - | yes: a secret and its QR address | yes |
| `backup-code` (`BackupCode`) | `BackupCode\BackupCodeMethod` | `omniscient/omniauth` | second | - | yes: draws a new set | yes |
| `trusted-device` (`TrustedDevice`) | `TrustedDevice\TrustedDevices` | `omniscient/omniauth` | second, read from the cookie (`Method\Ambient`) | - | - (given after a second factor with `trust`) | yes: every browser forgotten |

A first factor finds the account; a second one is asked of an account already found. The default
rule ([The second-factor rule](second-factor.md)) asks a second factor after a password, a link or
an identity provider, never after a passkey.

### Identity providers

The sign-in through identity providers (OAuth 2, OpenID Connect) is the core's own, under
`Omniauth\OAuth\`: the state, PKCE and the nonce, the linking by the provider's subject, the
sign-up of an unknown identity ([Identity providers](oauth.md)). It requires no OAuth library;
each provider is a package of its own.

| Package | Provider | Class, factory | What it checks |
|---|---|---|---|
| [`omniauth/google`](https://github.com/glitchr-studio/omniauth-google) | Google (OpenID Connect, over league/oauth2-google) | `Omniauth\Google\GoogleProvider`, `GoogleFactory` (entries named `google` or of `type: google`) | scopes `openid email profile`, the nonce, the ID token's issuer, audience, authorized party, lifetime, nonce and Workspace domain (`hd`); `email_verified`; the userinfo when the ID token has no address |
| `omniscient/omniauth` | any league/oauth2-client provider, by hand | `Omniauth\OAuth\Provider\LeagueProvider` | the state and the S256 challenge in the address, the code exchanged with the verifier, the subject, address and name read from the resource owner's details |
| `omniscient/omniauth` | none: an authorization server in memory, for tests | `Omniauth\OAuth\Testing\FakeProvider` | a code once, for its client and return address, with the verifier of its challenge and the nonce |
| omniauth/oauth | - | - | **abandoned** since 2026-10-23: its last release holds no code and requires `omniscient/omniauth`, whose `Omniauth\OAuth\` has the same namespace and class names |

### Decisions it carries

Marco's decisions of 2026-10-20 (QA1 to QA9 of the omniauth plan), which the code holds:

- **QA1, no second factor after a passkey** and **QA2, a second factor after an identity
  provider**: `EnrolledSecondFactors::AFTER` is `[Password, Link, OAuth]`.
- **QA3, the linking rule**: an identity is found by the provider's subject first; an address the
  provider vouches for links an account only when it has no second factor (`NoSecondFactorLinking`).
- **QA4, the sign-in link**: 15 minutes, once, its SHA-256 kept and never its value; a new link at
  each request.
- **QA5, the secrets kept hashed or sealed**: the e-mailed code as an HMAC keyed by the host's
  secret and bound to the account; the TOTP secrets sealed with sodium (omniauth/otp).
- **QA8, the bridges in omnibridge**, omniauth only suggested there; **QA9**, one relying party for
  the passkeys of a site and its twin (omniauth/webauthn).

### How this manual is organised

- [Installation](installation.md): requirements, a whole sign-in in plain PHP, then the hosts.
- [Symfony and Laravel](hosts.md), [Symfony](symfony.md) (`omniauth.yaml`, the authenticator, the
  routes) and [Laravel](laravel.md) (`config/omniauth.php`, the guard, the routes).
- [Concepts](concepts.md): the conductor, the attempt, the result and every value it carries;
  [The contracts](contracts.md): the accounts, the stores of each proof, the host's duties.
- The methods: [password](password.md), [sign-in link](link.md), [code by e-mail](email-code.md),
  [backup codes](backup-codes.md), [trusted devices](trusted-devices.md),
  [identity providers](oauth.md); [the second-factor rule](second-factor.md); [events](events.md).
- [Configuration reference](configuration.md): every constructor's options, the throttle's limits.
- [Errors](errors.md): what is a failure in the result, and what is an exception.
- [Testing](testing.md), [the Docker harness](harness.md),
  [writing a method or a provider](providers.md), [Verified, and not](verified.md).
