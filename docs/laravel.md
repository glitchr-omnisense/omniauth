---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel (`Omnibridge\Laravel\Auth\`, step AU8) wires omniauth under Laravel as
omnibridge/symfony wires it under Symfony, from `config/omniauth.php` - the same keys as
`omniauth.yaml`, so a site and its Laravel twin say the same thing - plus what Laravel needs of its
own: a guard and a user provider, the gate's answers to Security's attributes, the sign-in pages,
the sign-out and a signed remember-me cookie. This page describes omnibridge/laravel's `Auth\`
namespace at its 1.x branch.

## Turning it on

```sh
composer require omniscient/omniauth omnibridge/laravel
php artisan vendor:publish --tag=omniauth-config      # config/omniauth.php, to edit
```

```php
// config/omnibridge.php
'auth' => true,

// config/auth.php
'guards' => ['web' => ['driver' => 'omniauth', 'provider' => 'omnibase']],
'providers' => ['omnibase' => ['driver' => 'omniauth']],
```

`OmnibridgeServiceProvider` registers `Auth\AuthServiceProvider` when the class `Omniauth\Omniauth`
exists; the provider wires nothing at boot unless `omnibridge.auth` is on (`true`; `false` or
`null` is off, the default). The controllers read the **default** guard, which must be the
`omniauth` one (`LogicException` otherwise).

## config/omniauth.php

Each method's key turns it on; `null` (or `false`) turns it off. The defaults are those the
provider fills in (`Auth\Settings`), the same as `omniauth.yaml`'s where both have the key.

| Key | Default | |
|---|---|---|
| `secret` | `null`: `app.key` (a `base64:` key decoded) | keys the e-mailed codes' hashes and signs the trusted devices' tokens: the Symfony site's `kernel.secret`, for its trusted browsers and codes to be good here |
| `login_route` | `security_login` | where a refused or abandoned attempt goes back to |
| `stores` | `'omnibase'` | `'omnibase'`: omnibase's `Base\Auth\` stores on the entity manager, each where the container does not bind its contract already; or a map of bindings by key (`accounts`, `passwords`, `link_tokens`, `email_codes`, `backup_codes`, `trusted_version`, `totp_secrets`, `identities`, `passkeys`, `sender`), each contract's own name for a key left out |
| `password` | on | `null` or `false`: off |
| `password.path` | `/login` | the sign-in page: GET shows it, POST signs in |
| `password.form` | `null` | the form's name, its fields read under it; null: at the top of the request |
| `password.identifier`, `.password`, `.remember` | `_username`, `_password`, `_remember_me` | the form's fields |
| `password.target_path_parameter` | `_target_path` | a posted (or query) address of this site to go to once signed in |
| `password.default_target_path` | `/` | where to go otherwise (after Laravel's intended URL) |
| `password.csrf` | `['field' => '_csrf_token', 'intent' => 'authenticate']` | checked through `Omnibridge\Contract\Tokens`; `['enabled' => false]` turns it off |
| `password.template` | `security/login.html.twig` | the page's template, rendered by `Base\Screen\Negotiation` |
| `password.allow_login_token` | `true` | the form's `allow_login_token` variable: the page offers the sign-in link |
| `password.hasher` | `null`: `NativePasswordHasher` (bcrypt, cost 13) | otherwise `security.yaml`'s `password_hashers` keys (`algorithm`, `cost`, `time_cost`, `memory_cost`, `migrate_from`...), read by Symfony's `PasswordHasherFactory` |
| `link` | `null` (off) | needs a `Sender` |
| `link.route`, `link.path` | `security_loginWithToken`, `/login/token/{token?}` | GET with a token signs in; GET without one shows the page; POST sends a link |
| `link.parameter` | `token` | |
| `link.lifetime` | `900` | seconds |
| `link.template` | `security/login_token.html.twig` | |
| `link.form`, `link.email` | `login_token`, `email` | the address form's name and field |
| `link.csrf` | `['field' => '_csrf_token', 'intent' => 'login_token']` | |
| `email_code` | `null` (off) | `['digits' => 6, 'lifetime' => 600]`; needs a `Sender` |
| `backup_codes` | `null` (off) | `['count' => 8]` |
| `totp` | `null` (off) | `['leeway' => 0, 'period' => 30, 'digits' => 6, 'issuer' => null, 'server_name' => null, 'secret_key' => null]`; omniauth/otp |
| `totp.secret_key` | `null` | `env('OMNIAUTH_TOTP_KEY')`, base64 of 32 bytes, the Symfony site's very key: the `TotpSecrets` binding wrapped in `SealedTotpSecrets` over a `SodiumSecretBox` |
| `trusted_device` | `null` (off) | `['lifetime' => 2592000, 'cookie' => 'trusted_device', 'firewall' => 'main']` |
| `passkey` | `null` (off) | `['rp_id' => null, 'rp_name' => null, 'origins' => [], 'timeout' => null, 'remember' => true, 'options_path' => '/login/passkey/options', 'options_route' => 'passkey_login_options', 'path' => '/login/passkey', 'route' => 'passkey_login']`; omniauth/webauthn |
| `oauth` | `null` (off) | `['link_by_email' => null, 'sign_up_route' => null, 'providers' => [...]]` |
| `oauth.providers.<name>` | | `client_id` (`''`: not served), `client_secret`, `redirect_uri` (null: the path of `check_path`), `scopes`, `hosted_domain`, `type`, `service` (a binding that is the provider), `start_route` / `start_path` (`oauth_<name>`, `/connect/<name>`), `check_route` / `check_path` (`oauth_<name>_check`, `/connect/<name>/check`) |
| `oauth.link_by_email` | `null`: `NoSecondFactorLinking` | `true`, `false`, or a binding (`Omniauth\OAuth\Contract\EmailLinking`) |
| `oauth.sign_up_route` | `null` | where an unknown identity goes (its `PendingIdentity` in the session) |
| `second_factor.after` | `null`: `[password, link, oauth]` | the first factors a second one follows |
| `second_factor.form_path`, `.form_route` | `/login/2fa`, `2fa_login` | |
| `second_factor.check_path`, `.check_route` | `/login/2fa/check`, `2fa_login_check` | |
| `second_factor.template` | `security/login_2fa.html.twig` | |
| `second_factor.code`, `.trusted` | `_auth_code`, `_trusted` | |
| `second_factor.csrf` | `['field' => '_csrf_token', 'intent' => 'two_factor']` | |
| `second_factor.open_routes` | `[]` | routes a visitor between two factors may still open, besides the page, its check and the sign-out |
| `logout.path`, `.route` | `/logout-request`, `security_logoutRequest` | the sign-out itself (GET or POST), the session's token required: 403 without it |
| `logout.page_path`, `.page_route` | `/logout`, `security_logout` | the page: with the token, straight to the sign-out; without, asks to confirm |
| `logout.template` | `security/logout.html.twig` | |
| `logout.csrf` | `['field' => '_csrf_token', 'intent' => 'logout']` | |
| `logout.target` | `/` | a path, or a route name |
| `remember_me` | `['cookie' => 'REMEMBERME', 'lifetime' => 604800]` | `null`: no remember-me |
| `role_hierarchy` | `[]` | `security.yaml`'s `role_hierarchy`: a role and the roles it includes |
| `throttle` | `['store' => null, 'pool' => null, 'limits' => []]` | `false`: no throttle. `store`: a Laravel cache store (null: the default one); `pool`: a PSR-6 pool's binding instead (the Symfony site's, so both hosts count the same tries); `limits`: `['password' => [5, 60]]` per scope. The TOTP steps used are kept there too |
| `captcha` | `['pool' => null, 'firewalls' => ['main']]` | with omnibase 4.x's stores: the captcha after failed sign-ins (below) |
| `mail` | `['mailer' => null, 'from' => null, 'loopback' => false, 'test_recipients' => [], 'project_dir' => null]` | with omnibase 4.x: the sign-in e-mails (below) |
| `messages` | `[]` | by failure or `failure:reason`, `%reason%` replaced: the same lookup and defaults as under [Symfony](symfony.md#messages) |
| `routes` | `true` | `false`: declare the routes yourself with `Omnibridge\Laravel\Auth\Routes::register($router, $settings)` |

## Bindings

| Binding | What |
|---|---|
| `Omniauth\Omniauth` | the conductor (singleton) over the methods turned on |
| `Omniauth\Method\Methods`, `omniauth.second_factors` | the methods; the second factors alone |
| `Omniauth\Contract\SecondFactorPolicy` | `EnrolledSecondFactors`; omnibase's `Base\Auth\Policy` around it when `stores` is `'omnibase'` and `Base\Service\SecurityPolicy` is bound |
| `Omniauth\Contract\State` (`Auth\SessionState`) | the request's session store (`session.store`); nothing kept without one |
| `Omniauth\Contract\Throttle` | `CacheThrottle` on `throttle.store` or `throttle.pool` |
| `Symfony\Component\PasswordHasher\PasswordHasherInterface` | the hasher of `password.hasher` |
| `Omniauth\OAuth\Provider\Providers` | from `oauth.providers`, through omniauth/google's `GoogleFactory` (when installed) and every binding tagged `omniauth.oauth_provider_factory` |
| `Auth\Settings`, `Auth\Contexts`, `Auth\RememberMe`, `Auth\Roles`, `Auth\Pages`, `Auth\OmniauthUserProvider` | the bridge's own |
| `omniauth.totp.sealed_secrets` | the sealed TOTP store, when `totp.secret_key` is set and `TotpSecrets` is an alias of another binding |

At boot the provider also: registers the guard driver and the user provider driver `omniauth`;
answers Security's attributes from the gate (`Gate::before`, `Auth\Roles`); leaves the
`trusted_device` and remember-me cookies out of `EncryptCookies` (the Symfony site writes and
reads them plain); appends `Middleware\SecondFactorPending` to the `web` group, before the auth
middleware; registers the routes; and, with omnibase's stores, its sign-in listeners.

## Routes

In the `web` group, without Laravel's CSRF middleware (the forms' tokens are checked by the bridge
through the `Tokens` contract, with Security's field names):

| Name (default) | Path (default) | Methods | Action |
|---|---|---|---|
| `security_login` (`login_route`) | `/login` | GET, POST | `SignIn::login`: the page, or the password |
| `security_loginWithToken` | `/login/token/{token?}` | GET | `SignIn::link`: with a token, signs in |
| (unnamed) | `/login/token/{token?}` | POST | `SignIn::requestLink`: sends a link; the same answer whether the address has an account |
| `2fa_login` | `/login/2fa` | GET | `Ceremony::secondFactorPage` |
| `2fa_login_check` | `/login/2fa/check` | GET, POST | `SignIn::secondFactorCheck` |
| `passkey_login_options` | `/login/passkey/options` | POST | `Ceremony::passkeyOptions` |
| `passkey_login` | `/login/passkey` | POST | `SignIn::passkey` |
| `oauth_<name>` | `/connect/<name>` | GET | `Ceremony::oauthStart` (404 for a provider not served) |
| `oauth_<name>_check` | `/connect/<name>/check` | GET | `SignIn::oauthCheck` |
| `security_logout` | `/logout` | GET | `SignIn::logoutPage` |
| `security_logoutRequest` | `/logout-request` | GET, POST | `SignIn::logout` |

The link routes exist with `link` on, the passkey routes with `passkey`, the password route with
`password`. `Controllers\SignIn` answers the `Result` as omnibridge/symfony's authenticator does
(the same redirects, the same JSON for a passkey, the same session-kept identity for the sign-up),
with a `Refusal` (message key and data, factor, failure, reason) kept in the session under
`omnibridge.auth.last_error` for the page that follows. The second factor's page receives the same
variables as under [Symfony](symfony.md#the-routes), so the Symfony site's template renders
unchanged. Pages are rendered through the bound `Base\Screen\Negotiation` and answered with
`Cache-Control: no-store, private`.

## The guard

`Auth\OmniauthGuard` (a `StatefulGuard`) keeps the account's handle in the session
(`omnibridge.auth.handle`) and reads the account back through omniauth's `Accounts` at each
request, wrapped as `Auth\AccountUser` (the handle as its identifier, the account's
`getRoles()` as its roles; a method the wrapper has not is the account's). `Omnibridge\Contract\Access::getUser()`,
the screens' viewer and Twig's `app.user` unwrap it: the application sees its own object.

| Method | |
|---|---|
| `enter(Result $result)` | signs in an authenticated result: a new session id (data kept), the handle stored, the remember-me cookie when `Result::$remember` |
| `attempt(array $credentials, bool $remember)` | asks omniauth the password (`identifier`, `email` or `username`, and `password`); false when a second factor is required (the attempt waits in the session) |
| `pending()` | the attempt between two factors, or null |
| `account()` | the application's own object of the account signed in |
| `login()`, `loginUsingId()`, `once()`, `onceUsingId()`, `validate()`, `viaRemember()`, `logout()` | Laravel's `StatefulGuard`; `logout()` forgets the handle, regenerates the CSRF token, abandons omniauth's attempt and forgets the remember-me cookie |

`Auth\OmniauthUserProvider` (driver `omniauth`) finds accounts by handle or by identifier and checks
a password with the same hasher as `PasswordMethod` (a password longer than 72 bytes verifies as
under Symfony); it keeps no remember token.

The remember-me cookie (`Auth\RememberMe`) is `base64url(handle):expiry:signature`, the signature
an HMAC-SHA256 under `secret` of the account's class, handle, expiry, password hash and address
(Security's `signature_properties`): a new password or a new address forgets every browser. No
token is stored.

`Auth\Roles` answers the gate: `PUBLIC_ACCESS` and `IS_AUTHENTICATED_ANONYMOUSLY` to everybody,
`IS_AUTHENTICATED` and `IS_AUTHENTICATED_REMEMBERED` to an account signed in,
`IS_AUTHENTICATED_FULLY` to one not signed in by its remember-me cookie,
`IS_AUTHENTICATED_2FA_IN_PROGRESS` to the visitor between two factors, `IS_IMPERSONATOR` and
`IS_REMEMBERED` to nobody, `ROLE_*` to an account that holds it or a role above it in
`role_hierarchy`; any other ability is left to the gate's own definitions.

`Middleware\SecondFactorPending` sends a visitor between two factors to the second factor's page
from any route but the page, its check, the sign-out's two routes and `second_factor.open_routes`.

## With omnibase

With `stores: 'omnibase'` and omniscient/omnibase 4.x, `Auth\OmnibaseEffects` registers what a
sign-in does around omniauth under Symfony, with the same omnibase classes: the browsers an account
signs in from (`Base\Security\ConnectionTracker`), the new device warned of, the captcha after
failed sign-ins from an address (`Base\Security\SignInCaptcha`, its gateway `config/omnishield.php`'s,
counted in `captcha.pool`), "welcome back" to a disabled account, the sign-in e-mails written by
`Base\Notifier\Mail\SignInMails` and sent by Laravel's mailer (`mail`), omnibase's
`NotifierSender` as omniauth's `Sender`, and `Middleware\EnrolmentPrompt` (the second factor's
enrolment an account is sent to). Without omnibase 4.x but with its stores, the listeners
`Base\Auth\Listener\UpdateAccount` and `Auth\Listener\WelcomeFlash` (the word of welcome) listen to
`Omniauth\Event\SignedIn`.
