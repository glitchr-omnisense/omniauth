---
title: Identity providers (OAuth 2, OpenID Connect)
order: 8
---

# Identity providers (OAuth 2, OpenID Connect)


```sh
composer require omniscient/omniauth omniauth/google
```

The mechanics every provider shares are omniauth's own, under `Omniauth\OAuth\` (they were the
package omniauth/oauth until 2026-10-23: same namespace, same class names); omniauth requires no
OAuth library. Each provider is a package of its own - [omniauth/google](https://github.com/glitchr-studio/omniauth-google)
first, which brings league/oauth2-client and league/oauth2-google. No bundle, no framework: the
links between your accounts and their providers stay in your table, behind omniauth's
`Contract\Identities` (a provider, the person's identifier there - `sub` -, the account).

The whole thing by hand - the in-memory stores of `Omniauth\Testing` stand in for yours, and
`Omniauth\OAuth\Testing\FakeProvider` for Google and the person consenting there:

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\Context;
use Omniauth\Method\Methods;
use Omniauth\OAuth\NoSecondFactorLinking;
use Omniauth\OAuth\OAuthMethod;
use Omniauth\OAuth\Provider\Providers;
use Omniauth\OAuth\Testing\FakeProvider;
use Omniauth\Omniauth;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\TestAccount;

$store = new InMemoryStore($ringo = new TestAccount('42', 'ringo', 'ringo@example.org'));
// Yours: omniauth/google's new GoogleProvider($clientId, $clientSecret, '/login/google/callback')
$google = new FakeProvider('google', redirectUri: 'https://example.org/login/google/callback');

$session = [];                                             // $_SESSION, in a real page
$methods = new Methods();
$methods->add(new OAuthMethod($store, $store, new Providers($google), new NoSecondFactorLinking($methods)));
$omniauth = new Omniauth(accounts: $store, methods: $methods, state: new ArrayState($session));
$context = new Context(origin: 'https://example.org', host: 'example.org', ip: '203.0.113.7');

// /connect/google: the visitor is sent to the provider.
$challenge = $omniauth->begin('oauth', $context, ['provider' => 'google']);
// header('Location: '.$challenge->payload); the person consents; the browser comes back with ?code=…&state=…
$return = $google->consent($challenge->payload, ['sub' => '108204', 'email' => 'ringo@example.org', 'email_verified' => true]);

// /login/google/callback: the return's query, as it is.
$result = $omniauth->verify('oauth', $context, $return);
echo $result->status->value, ' as ', $result->account->identifier, ', linked to google ', $ringo->identities['google'], "\n";
// authenticated as ringo, linked to google 108204

// Someone no account knows: a result for the sign-up page, no account made.
$return = $google->consent($omniauth->begin('oauth', $context)->payload, ['sub' => '9001', 'email' => 'john@example.org', 'email_verified' => true]);
$result = $omniauth->verify('oauth', $context, $return);
echo $result->status->value, ': ', $result->identity->email, "\n";
// identity-unknown: john@example.org
```

What your pages do around it is the host's part: the start route redirects to
`$challenge->payload`; the return route hands its query to `verify()` and, on `Authenticated`,
renews its session id and keeps the account's handle; on `SecondFactorRequired` it shows the
second-factor page (an account that has one passes it after Google too: Marco's decision QA2); on
`IdentityUnknown` it keeps `$result->identity` **in its session** and shows its sign-up, then calls
`OAuthMethod::adopt($account, $identity)` once the account exists; on a failure, the sign-in page
with `$result->reason` translated.

Next: the method and its linking rule, then the providers, below.

## The method


`OAuthMethod` is a first factor (`Factor::OAuth`), `Challenging` and `Enrolling`:

```php
new OAuthMethod(
    accounts: $accounts,          // Omniauth\Contract\Accounts
    identities: $identities,      // Omniauth\Contract\Identities: provider + sub -> account
    providers: new Providers(new GoogleProvider($id, $secret, '/login/google/callback')),   // omniauth/google
    byEmail: new NoSecondFactorLinking($methods),   // true (any account), false (never), or a rule
);
```

### The trip

| | |
|---|---|
| `begin()` (`['provider' => 'google']`, or nothing when one provider is served) | draws a state, a PKCE verifier and a nonce (32 random bytes each, base64url), keeps them in the attempt with the provider's name, answers `Challenge::redirect()` with the authorization address: the state, the S256 challenge, the nonce, the return address and scopes; it keeps the origin of the request too (`Context::$origin`), on which a return path (`/login/google/callback`) is completed - here and again at the exchange. The verifier never leaves the server. |
| `verify()` (the return's query: `code`, `state`, or `error`) | takes what the attempt kept - **once**: a return replayed finds nothing, `expired` -, compares the state in constant time **before anything else** (a return this visitor did not begin is not read further: no exchange, no request), then has the provider exchange the code **with the verifier** and read the person. |

The state stops a return the visitor did not begin (login CSRF: an attacker's own code landed in
the victim's browser). PKCE stops a code used in a trip it was not issued for (a code stolen from
a log or a referrer, or injected with the victim's state): the provider refuses to exchange it with
another trip's verifier. The nonce ties the ID token to the trip. What the attempt keeps lives as
long as the attempt (ten minutes).

### Who it is: the linking rule (QA3)

1. **The account linked to the provider's subject** (`Identities::owner($provider, $sub)`), whatever
   the address says now: Google's `sub` is the person's stable identifier there; an address can
   change hands.
2. Else, **only when the provider says the address is verified** (Google's `email_verified`), the
   account of that address (`Accounts::byIdentifier()`, then `Accounts::email()` compared, case
   aside: a user name that looks like the address is not it) - linked there and then
   (`Identities::link()`), unless:
   - `byEmail` refuses it: `false` never links by an address; `NoSecondFactorLinking` refuses an
     account that set up a second factor (Marco's QA3: never for an account with a second factor or
     an administration role - omnibase's own rule adds the roles, step AU4);
   - the account is linked to another subject of that provider already: an address does not move
     a link.
   Then: `not-enrolled`, reason `link-from-settings` - the person signs in otherwise and links the
   provider from the settings.
3. Else **nobody**: `Status::IdentityUnknown`, `$result->identity` (a `PendingIdentity`: provider,
   subject, address, whether it is verified, name). No account is created here - not even a
   transient one (lesringostars' hwi/oauth-bundle made one, and an address without an account
   ended in a 500 "User identifier is NULL"). The host shows its sign-up, prefilled; once the
   account exists, `adopt($account, $identity)` links it. Keep the identity in the session, never
   in a form field.

An address the provider does not vouch for is **never looked up**: an unverified address finds no
account, and the sign-up knows it is not proved (`emailVerified: false`).

### After it

The conductor does what it does after any first factor: `Accounts::check()` (banned, locked...),
then the second-factor rule - **an account that has a second factor passes it after Google**
(Marco's QA2: `EnrolledSecondFactors::AFTER` holds `Factor::OAuth`; lesringostars today signs in
by Google without it).

### From the settings

```php
$enrolment = $omniauth->enrol(Factor::OAuth, $account);
$challenge = $enrolment->begin($context, ['provider' => 'google']);   // redirect
$enrolled = $enrolment->confirm($context, $return);                   // the return's query
```

The same trip, state and PKCE included, in the enrolment's own attempt (a sign-in in progress is
not touched). The address does not matter there (the person is signed in); the subject is refused
when another account holds it (`linked-elsewhere`), and an account keeps one identity per provider
(`other-identity`). With one return address per provider, the host tells the two apart by whether
the visitor is signed in: signed in, `enrol()->confirm()`; not, `verify()`.

### Refusals

`bad-credentials` with a stable reason: `unknown-provider` (at the beginning), `state-mismatch`,
`provider-denied` (the person said no: `error=access_denied`), `provider-error`, `missing-code`,
`exchange-failed` (the provider refused the code: used, expired, or not this trip's verifier),
`invalid-id-token`, `no-subject`, `hosted-domain`, and from the settings `linked-elsewhere`,
`other-identity`; `not-enrolled` / `link-from-settings` (above); `expired` when the attempt kept
nothing. The provider's own message is for the server's log (`ProviderError::getMessage()`), not
the page.

A link keeps what the provider said of the address at that moment (`Identities::link()`'s `$email`
and `$emailVerified`), and every sign-in by an identity marks it used (`Identities::used()`, the
method's clock). Turning a link off is the store's `Identities::unlink($account, $provider)`, which
the host's settings page calls; omnibase's store is `Base\Auth\UserIdentities` on its
`userIdentity` table.

## The providers


A provider (`Provider\Provider`) gives the authorization address for an `Authorization` (state,
verifier, nonce, the origin the trip began at) and, for the code that came back, the `Identity` it
vouches for - or throws a `ProviderError` with a stable reason. omniauth has none that speaks
to a real server: **each provider is a package of its own** (Marco's decision, 2026-10-21, the shape
of omnishield's captchas) - [omniauth/google](https://github.com/glitchr-studio/omniauth-google)
first, over league/oauth2-google.

### The return address

A provider's return address is its configuration's, never a request's: an absolute address, or a
**path** (`/login/google/callback`). A path is completed by the origin of the request that began
the trip (`Authorization::returnAddress()`, the origin kept in the attempt with the state): a site
served on two hosts, or a long-running worker (FrankenPHP) that serves several, sends each visitor
back to the host they left from - never to the first host the worker served -, and the exchange
repeats that trip's address, as an authorization server compares them. Each address must be one of
the client's registered return addresses at the provider.

### By configuration

`Providers::configured()` reads the tree a host's `omniauth.yaml` (or `config/omniauth.php`) holds,
each entry made by the first factory (`Provider\ProviderFactory`) that knows its kind:

```php
Providers::configured([
    'google' => ['client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => '/login/google/callback'],
], new Omniauth\Google\GoogleFactory());
```

An entry without a `client_id` is not served (lesringostars declares providers with no credentials:
they stay out); one without a `redirect_uri`, or that no factory makes, is a mistake
(`InvalidArgumentException`, naming the package to install). Providers given as they are (a
service) sit beside them: `new Providers($given, ...Providers::configured($config, ...$factories))`.

`Providers::fromConfig()` (deprecated, kept one release) makes its Google entries through
omniauth/google's factory when that package is installed; `Omniauth\OAuth\Provider\GoogleProvider`
is an alias of omniauth/google's class for the same release.

### Over league/oauth2-client

`Provider\LeagueProvider` makes any league/oauth2-client provider an omniauth `Provider`: the
authorization address with omniauth's state and S256 challenge, the code exchanged with its
verifier, the person read from the resource owner's details (`$fields` names the subject, the
address, whether it is verified, the name). omniauth/google's `GoogleProvider` is built on it; a
provider without a package of its own can be served through it by hand
(league/oauth2-facebook, a `GenericProvider` on a provider's three addresses). league is not
required by omniauth - the provider packages require it -, so the class loads without it but is
usable only with it. PKCE is omniauth's: leave the league provider's `pkceMethod` unset.
(`Omniauth\Google\LeagueProvider` is an alias of it for one release.)

### In tests

`Testing\FakeProvider` is an authorization server in memory: `consent($authorizationUrl, $person)`
gives the return's `code` and `state`, `deny()` an `access_denied`; `identify()` exchanges a code
once, only with the verifier whose challenge the address carried and for the return address it was
given to, and checks the nonce. Its return address may be a path too. Under it a host's tests sign
in with no network, and a forged or injected return fails as it would at Google.
