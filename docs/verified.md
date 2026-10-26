---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-25 in the [harness](harness.md) (`docker compose run --rm omniauth test`, PHP
8.4.26, PHPUnit 11.5.57), the four packages from their checkouts: 235 tests, 2490 assertions,
green - the core 126, omniauth/google 28, omniauth/otp 47, omniauth/webauthn 34. One skipped:
`ProvidersTest::testTheFormerFromConfigNeedsGooglesPackage`, which needs omniauth/google absent
(omniauth/google's own tests cover the deprecated path).

The core's tests (every file also linted on PHP 8.2):

- each method's way through and its refusals on the in-memory stores - wrong secret, unknown
  address answered as a wrong password, expired, used twice, throttled, a banned account, the
  second factor offered, passed, not offered, its attempt expired;
- QA1 and QA2 through the default rule;
- the trusted-device tokens equal to those scheb/2fa 8.6.1's `JwtTokenEncoder` writes for the same
  input, whole seconds and microseconds, each read by the other (recorded, and live with scheb/2fa
  installed in the harness);
- hashes written by symfony/password-hasher 8.1 ("auto": bcrypt, cost 13), one of a 102-byte
  password that `password_verify()` alone refuses;
- `docs/installation.md` and `docs/oauth.md` run as written (`Tests/DocsTest.php`);
- in plain PHP (`Tests/BareTest.php`), a whole sign-in by hand loading nothing but omniauth, the PSR
  interfaces and symfony/password-hasher; a sign-in through a fake identity provider loading no
  OAuth library; every class of the package loading with them alone, `LeagueProvider` included;
- the identity providers' trip (state, PKCE, nonce, forged and injected returns, the linking rule,
  a return path on the host of each trip) on the fake provider, and `LeagueProvider` over a generic
  league provider with recorded answers;
- the providers' conformance suite (`Omniauth\Testing\ProviderContractTests`) green for
  `FakeProvider` and omniauth/google, on recorded answers.

The examples of this manual were run in the harness on 2026-10-25, and their output pasted as
printed: the installation, the identity providers, the sign-in link, events, testing, the
throttle's limits, errors, `LeagueProvider`'s address.

The bridges are tested in omnibridge: `tests/AuthTest.php` of omnibridge/symfony (a kernel signing
in through omniauth alone: each method, the second factor, its page and nobody else's, refusals,
CSRF, throttle, remember-me, trusted device, a simulated Google, the return address on the host of
each request under one container) and of omnibridge/laravel; they were not run for this manual.

## What is not verified

- An application's own stores: omnibase fills them (`Base\Auth\`, step AU4) and tests them in its
  own suite.
- A cookie written by a running scheb/2fa on an application: the encoder is compared, not a
  browser's cookie.
- Concurrent tries against `CacheThrottle` (read then written, not atomic: a host that needs it
  exact uses a store that counts atomically).
- A real identity provider: Google's answers are recorded from its documentation
  (omniauth/google's manual).
- The [Symfony](symfony.md) and [Laravel](laravel.md) pages were written from omnibridge's code at
  its 1.x branch; the `omniauth.yaml` defaults were resolved with Symfony's Config `Processor` in
  omnibridge/symfony's image, the Laravel defaults read from `Auth\Settings`.

## Responsibility

omnisense/omniauth and its `omniauth/*` packages are independent code: omniauth/google is an
unofficial client of Google's sign-in, not affiliated with or endorsed by Google, and whoever runs
it complies with Google's terms for its APIs. The MIT licence covers this code only.
