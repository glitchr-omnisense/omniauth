---
title: Symfony
order: 15
---

# Symfony

omniauth runs without a framework ([Installation](installation.md)); in a Symfony application,
omnibridge/symfony (`Omnibridge\Symfony\Auth\`) does the wiring: the conductor and its methods from
`config/packages/omniauth.yaml`, one authenticator for every way in, the second factor's token,
voter, trust resolver and gate, the ceremonies' routes and their controller. This page describes
omnibridge/symfony's `Auth\` namespace at its 1.x branch.

## Registering

```sh
composer require omnisense/omniauth omnibridge/symfony
```

`Omnibridge\Symfony\OmnibridgeBundle` (omnibridge/symfony's bundle) registers omniauth's container
extension, `Omnibridge\Symfony\Auth\DependencyInjection\OmniauthExtension` (alias `omniauth`), when
the class `Omniauth\Omniauth` exists; an application's own bundle can do the same with
`OmniauthExtension::register($container)` (omnibase's `BaseBundle` does). Without an
`omniauth.yaml`, or with one that holds no key, the extension loads nothing: no service, no route.

```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            custom_authenticators: [Omnibridge\Symfony\Auth\OmniauthAuthenticator]
            entry_point: Omnibridge\Symfony\Auth\OmniauthAuthenticator   # optional: start() sends to login_route
            remember_me: { secret: '%kernel.secret%' }                   # Security's own, used when omniauth says "remember"
            logout: { path: security_logout }
    access_control:
        - { path: ^/login/2fa, roles: IS_AUTHENTICATED_2FA_IN_PROGRESS }

# config/routes/omniauth.yaml
omniauth: { resource: ., type: omniauth }

# config/packages/omniauth.yaml - each key turns its method on
omniauth:
    password: { routes: [security_login] }
    link: { route: security_loginWithToken }        # 15 minutes, once, hashed
    email_code: ~
    backup_codes: ~
    trusted_device: ~                                # scheb/2fa's cookie and tokens
    totp: { secret_key: '%env(OMNIAUTH_TOTP_KEY)%' } # omniauth/otp; the secrets sealed at rest
    passkey: { rp_id: '%env(RELYING_PARTY_ID)%' }    # omniauth/webauthn
    oauth:
        providers:
            google: { client_id: '%env(GOOGLE_CLIENT_ID)%', client_secret: '%env(GOOGLE_CLIENT_SECRET)%' }   # omniauth/google
```

A method whose package is missing cannot be turned on: `totp` without omniauth/otp, `passkey`
without omniauth/webauthn, `oauth` without `Omniauth\OAuth\OAuthMethod` stop the container's
compilation with a `LogicException` naming the package to require
(`omniauth.yaml turns "totp" on, which needs omniauth/otp: composer require omniauth/otp.`).

## The configuration tree

`password` and `throttle` are on unless set to `false` (`canBeDisabled`); `link`, `email_code`,
`backup_codes`, `trusted_device`, `totp`, `passkey` and `oauth` are off unless their key is present
(`canBeEnabled`: `~` turns one on with its defaults). The defaults below were read by resolving the
tree with Symfony's Config `Processor` in omnibridge/symfony's image on 2026-10-25.

| Key | Type | Default | |
|---|---|---|---|
| `secret` | string | `%kernel.secret%` | keys the e-mailed codes' hashes and signs the trusted devices' tokens (scheb/2fa signed them with `kernel.secret`) |
| `login_route` | string | `security_login` | the sign-in page: where a refused or abandoned attempt goes back to, and the entry point's address |
| `stores.accounts` | service id | `Omniauth\Contract\Accounts` | the application's accounts |
| `stores.passwords` | service id | `Omniauth\Contract\Passwords` | |
| `stores.link_tokens` | service id | `Omniauth\Contract\LinkTokens` | |
| `stores.email_codes` | service id | `Omniauth\Contract\EmailCodes` | |
| `stores.backup_codes` | service id | `Omniauth\Contract\BackupCodes` | |
| `stores.trusted_version` | service id | `Omniauth\Contract\TrustedVersion` | |
| `stores.totp_secrets` | service id | `Omniauth\Contract\TotpSecrets` | decorated by the sealed store when `totp.secret_key` is set |
| `stores.identities` | service id | `Omniauth\Contract\Identities` | |
| `stores.passkeys` | service id | `Omniauth\WebAuthn\Contract\Passkeys` | |
| `stores.sender` | service id | `Omniauth\Contract\Sender` | sends the links and the codes |
| `password.enabled` | bool | `true` | |
| `password.routes` | list of route names | `[security_login]` | a POST to one of them is a password sign-in |
| `password.form` | string or null | `null` | the form's name, its fields read under it; null: at the top of the request |
| `password.identifier` | string | `_username` | the field of what the person types |
| `password.password` | string | `_password` | |
| `password.remember` | string | `_remember_me` | the "remember me" box |
| `password.target_path_parameter` | string | `_target_path` | a posted (or query) address of this site to go to once signed in |
| `password.default_target_path` | string | `/` | where to go otherwise (after the firewall's saved target path) |
| `password.user_class` | class or null | `null` | the user class whose hasher `security.yaml`'s `password_hashers` sets; null: `NativePasswordHasher` (bcrypt, cost 13) |
| `password.csrf.enabled` | bool | `true` | the form's token checked through `Omnibridge\Contract\Tokens` (when the container has that service) |
| `password.csrf.field`, `password.csrf.intent` | string | `_csrf_token`, `authenticate` | |
| `link.lifetime` | int, at least 60 | `900` | seconds a link is good for |
| `link.route` | route name or null | `null` | the link's page: a GET of it with a non-empty parameter signs in; null: the link is mounted but no request signs in by it |
| `link.parameter` | string | `token` | the route's parameter that holds the token |
| `email_code.digits` | int, 4 to 9 | `6` | |
| `email_code.lifetime` | int, at least 60 | `600` | seconds |
| `backup_codes.count` | int, at least 1 | `8` | codes drawn at each set-up |
| `trusted_device.lifetime` | int, at least 60 | `2592000` | seconds (30 days): the token's `exp` and the cookie's expiry |
| `trusted_device.cookie` | string | `trusted_device` | the cookie's name |
| `trusted_device.firewall` | string or null | `null` | the tokens' `fwl` claim; null: the name of the request's firewall (`main` when none) |
| `totp.leeway` | int, at least 0 | `0` | seconds before and after a period (scheb/2fa's `leeway`) |
| `totp.period` | int, at least 1 | `30` | |
| `totp.digits` | int, 6 to 10 | `6` | |
| `totp.issuer`, `totp.server_name` | string or null | `null` | the QR address's issuer and label suffix (scheb's `issuer`, `server_name`) |
| `totp.secret_key` | string or null | `null` | base64 of 32 bytes (from the vault or the environment, never in the repository): the secrets sealed at rest; null: kept as the store has them |
| `passkey.rp_id` | string or null | `null` | the relying party's id; null: each request's host |
| `passkey.rp_name` | string or null | `null` | null: the id |
| `passkey.origins` | list of strings | `[]` | the origins allowed; empty: the request's own |
| `passkey.timeout` | int or null | `null` | milliseconds the browser gives the person |
| `passkey.remember` | bool | `true` | Security's remember-me after a passkey (the posted JSON's `remember` wins) |
| `passkey.options_path`, `passkey.options_route` | string | `/login/passkey/options`, `passkey_login_options` | the request options (POST) |
| `passkey.path`, `passkey.route` | string | `/login/passkey`, `passkey_login` | the assertion (POST) |
| `oauth.link_by_email` | bool, `"true"`, `"false"`, service id or null | `null` | link an account by an address its provider vouches for: true, false, or a service (`Omniauth\OAuth\Contract\EmailLinking`); null: `NoSecondFactorLinking` |
| `oauth.sign_up_route` | route name or null | `null` | where an unknown identity goes (its `PendingIdentity` in the session); null: the sign-in page |
| `oauth.providers.<name>.client_id` | string | `''` | an entry without one is not served |
| `oauth.providers.<name>.client_secret` | string | `''` | |
| `oauth.providers.<name>.redirect_uri` | string or null | `null` | an absolute address or a path; null: the path of `check_route` |
| `oauth.providers.<name>.scopes` | list of strings | `[]` | omniauth/google's default when empty: `openid email profile` |
| `oauth.providers.<name>.hosted_domain` | string or null | `null` | Google Workspace domain, or `*` for any |
| `oauth.providers.<name>.type` | string or null | `null` | the kind a factory makes; null: the entry's name |
| `oauth.providers.<name>.class`, `.options`, `.fields` | string, any, any | `null`, `[]`, `[]` | declared; read by no factory shipped (see below) |
| `oauth.providers.<name>.service` | service id or null | `null` | a service that is the provider (`Omniauth\OAuth\Provider\Provider`), in place of the keys above |
| `oauth.providers.<name>.start_path`, `.start_route` | string or null | `/connect/<name>`, `oauth_<name>` | the trip out (GET) |
| `oauth.providers.<name>.check_path`, `.check_route` | string or null | `/connect/<name>/check`, `oauth_<name>_check` | the return (GET) |
| `second_factor.after` | list of factor names | `[password, link, oauth]` | the first factors a second one follows (`EnrolledSecondFactors`) |
| `second_factor.form_path`, `.form_route` | string | `/login/2fa`, `2fa_login` | the second factor's page (GET) |
| `second_factor.check_path`, `.check_route` | string | `/login/2fa/check`, `2fa_login_check` | where its form posts |
| `second_factor.template` | string | `security/login_2fa.html.twig` | the page's Twig template |
| `second_factor.code`, `.trusted` | string | `_auth_code`, `_trusted` | the form's fields: the code, "trust this browser" |
| `second_factor.csrf.enabled`, `.field`, `.intent` | bool, string, string | `true`, `_csrf_token`, `two_factor` | |
| `throttle.enabled` | bool | `true` | false: no throttle |
| `throttle.pool` | service id | `cache.app` | a PSR-6 pool: the tries counted, and the TOTP steps used |
| `throttle.limits.<scope>` | `[tries, seconds]` | `[]`: `CacheThrottle::LIMITS` | replaces a scope's limit ([Configuration](configuration.md#the-throttles-limits)) |
| `messages.<failure>` or `messages.<failure>:<reason>` | string | `[]` | a page's message for a failure, `%reason%` replaced ([below](#messages)) |

> [!WARNING]
> Known defect: `oauth.providers.<name>.class`, `options` and `fields` are accepted by the tree and
> handed to the provider factories, but no factory shipped reads them (omniauth/google's
> `GoogleFactory` reads `client_id`, `client_secret`, `redirect_uri`, `scopes`, `hosted_domain` and
> `type`), where the names suggest a league provider class, its options and its fields. A provider
> of another kind is given as a `service`, or made by an application's own factory.

## Services

| Service id | Class | |
|---|---|---|
| `Omniauth\Omniauth` | `Omniauth` | the conductor; public |
| `omniauth.methods` (alias `Omniauth\Method\Methods`) | `Methods` | every service tagged `omniauth.method` |
| `omniauth.method.password`, `.link`, `.email_code`, `.backup_codes`, `.trusted_device`, `.totp`, `.passkey`, `.oauth` | the methods | one per key turned on, tagged `omniauth.method`; the second factors (`email_code`, `backup_codes`, `totp`) also `omniauth.second_factor` |
| `omniauth.second_factors` | `Methods` | the second factors alone, for the rule and the address linking (no cycle through the first factors) |
| `omniauth.policy.default` (alias `Omniauth\Contract\SecondFactorPolicy`) | `EnrolledSecondFactors` | the rule; an application decorates the alias with its own |
| `Omnibridge\Symfony\Auth\SessionState` (alias `Omniauth\Contract\State`) | `SessionState` | the attempt in the current request's session; nothing kept without one |
| `Omniauth\CacheThrottle` (alias `Omniauth\Contract\Throttle`) | `CacheThrottle` | on `throttle.pool`, with `throttle.limits`; absent when `throttle` is off |
| `omniauth.oauth.providers` | `Omniauth\OAuth\Provider\Providers` | made by `Auth\OAuthProviders::create()` from `oauth.providers`; public |
| `omniauth.oauth.factory.google` | `Omniauth\Google\GoogleFactory` | when omniauth/google is installed; tagged `omniauth.oauth_provider_factory` |
| `omniauth.totp.box`, `omniauth.totp.sealed_secrets` | `SodiumSecretBox` (lazy), `SealedTotpSecrets` | when `totp.secret_key` is set: the sealed store decorates `stores.totp_secrets` |
| `Omnibridge\Symfony\Auth\Contexts` | `Contexts` | the request as omniauth's `Context` |
| `Omnibridge\Symfony\Auth\OmniauthAuthenticator` | | the authenticator a firewall names |
| `Omnibridge\Symfony\Auth\PendingVoter` | | tagged `security.voter` |
| `Omnibridge\Symfony\Auth\PendingTrustResolver` | | decorates `security.authentication.trust_resolver` |
| `Omnibridge\Symfony\Auth\SecondFactorGate` | | tagged `kernel.event_subscriber` |
| `Omnibridge\Symfony\Auth\CeremonyController` | | public, `controller.service_arguments` |
| `Omnibridge\Symfony\Auth\AuthRouteLoader` | | tagged `routing.loader`, type `omniauth` |

The stores are the application's: each contract's service by its own name, or the id `stores`
names (omnibase binds its `Base\Auth\` stores). The clock is the container's `clock` service when
it has one. omniauth's events go to Symfony's `event_dispatcher`: listen to
`Omniauth\Event\SignedIn` (and the others, [Events](events.md)) as to any event.

A class of the application that implements `Omniauth\OAuth\Provider\ProviderFactory` is
autoconfigured with the tag `omniauth.oauth_provider_factory` and makes the entries of its kind.

The bridge registers no console command and no Twig function.

## The authenticator

`OmniauthAuthenticator` knows a sign-in by its route (the router runs before the firewall):

| Request | Factor | Proof given to `verify()` |
|---|---|---|
| POST to one of `password.routes` | `password` | `identifier`, `password`, `remember` from the form's fields; the form's CSRF token checked first |
| GET of `link.route` with a non-empty `link.parameter` | `link` | `token` |
| POST to `passkey.route` | `passkey` | the body as `json`; `remember` from the JSON, else `passkey.remember` |
| POST to `second_factor.check_route` | the factor chosen on the page (else the first offered that is not a backup code); a backup code when the code is not the chosen factor's digits and backup codes are offered | `code`, `trust`; the CSRF token checked first |
| a provider's `check_route` | `oauth` | `provider`, and the query's `code`, `state`, `error` |

What it answers:

- **Authenticated**: a self-validating passport for the account (it must be a Symfony
  `UserInterface`), Security's `RememberMeBadge` enabled when `Result::$remember`, the
  `trusted_device` cookie when omniauth gave one (path `/`, HTTP only, `SameSite=Lax`, secure when
  the request is, for `trusted_device.lifetime`); a redirect to the posted `_target_path` when it is
  an address of this site, else the firewall's saved target path, else `default_target_path`; a
  passkey gets `{"status": "ok", "errorMessage": ""}`.
- **SecondFactorRequired**: a `PendingToken` (below) and a redirect to `second_factor.form_route`;
  a passkey gets the JSON with `redirect`.
- **Failed** and **IdentityUnknown**: a `SignInRefused` (a `CustomUserMessageAuthenticationException`
  carrying the factor, omniauth's failure and reason, and the identity). The password goes back to
  its own route, the others to `login_route`, the error in `_security.last_error`; a passkey gets
  `{"status": "failed", "errorMessage": ...}` with HTTP 401; a refused link empties the route's
  parameter and lets the page behind answer as for no link; a refused second factor goes back to
  its page with the error, or to `login_route` when the attempt is gone (`expired`, `no-attempt`);
  an unknown identity is kept in the session (`omnibridge.auth.pending_identity`) and goes to
  `oauth.sign_up_route` when one is set.

Every passport carries an `OmniauthBadge` (the `Result`): a listener of Security's own events
(`CheckPassportEvent`, `LoginSuccessEvent`, `LoginFailureEvent`) that does what an omniauth
listener does skips a passport that carries it.

The session keys it uses: `_security.last_username` (the identifier typed),
`_security.last_error`, `omnibridge.auth.factor` (the second factor chosen),
`omnibridge.auth.second_factor_error`, `omnibridge.auth.pending_identity` (an array: `provider`,
`subject`, `email`, `emailVerified`, `name`), and omniauth's own `omniauth.attempt` and
`omniauth.enrolment.<factor>`.

### Messages

A failure's message is looked up as `<failure>:<reason>`, then `<failure>`, in `messages` over
these defaults (Security's own message keys, which its translations already have):

| Failure | Default message |
|---|---|
| `bad-credentials`, `invalid-link`, `refused`, `not-enrolled` | `Invalid credentials.` |
| `bad-code`, `not-offered` | `code_invalid` |
| `expired`, `no-attempt` | `Authentication request could not be processed due to a system problem.` |
| `throttled` | `Too many failed login attempts, please try again in %minutes% minute.` (`%minutes%` is always 1) |
| `account-refused` | `Account is disabled.` |
| `csrf` (the bridge's own) | `Invalid CSRF token.` |

```yaml
omniauth:
    messages:
        'account-refused:banned': 'security.banned'
        'refused': 'security.refused.%reason%'
```

## The routes

`omniauth: { resource: ., type: omniauth }` imports, under the names and at the addresses
configured:

| Name (default) | Path (default) | Methods | Controller |
|---|---|---|---|
| `2fa_login` | `/login/2fa` | GET | `CeremonyController::secondFactor` |
| `2fa_login_check` | `/login/2fa/check` | any | answered by the authenticator; a stray GET goes back to the page |
| `passkey_login_options` | `/login/passkey/options` | POST | `CeremonyController::passkeyOptions` (with `passkey`) |
| `passkey_login` | `/login/passkey` | POST | answered by the authenticator (with `passkey`) |
| `oauth_<name>` | `/connect/<name>` | GET | `CeremonyController::oauthStart`: a redirect to the provider; 404 for a provider not served |
| `oauth_<name>_check` | `/connect/<name>/check` | GET | answered by the authenticator |

The sign-in page itself, the link's page and the sign-out are the application's routes
(`login_route`, `link.route`, the firewall's `logout`).

The second factor's page renders `second_factor.template` (TwigBundle required) with the variables
scheb/2fa's `FormController` gave it, so a template written for scheb/2fa renders unchanged:
`twoFactorProvider` (`totp`, `email` for omniauth's `email-code`, `backup-code`),
`availableTwoFactorProviders`, `authenticationError`, `authenticationErrorData`,
`displayTrustedOption` (true when trusted devices are on), `authCodeParameterName`,
`trustedParameterName`, `isCsrfProtectionEnabled`, `csrfParameterName`, `csrfTokenId`,
`checkPathRoute`, `checkPathUrl`, `logoutPath`; `?preferProvider=email` picks the factor shown. The
code by e-mail is sent when its page is first shown, and again once it has expired. The page is
answered with `Cache-Control: no-store`.

## Between two factors

- `PendingToken`: the token of a visitor whose first factor passed. It carries no role, is never
  "fully" signed in (`PendingTrustResolver` refuses `IS_AUTHENTICATED_FULLY` to it) and lists the
  factors offered (`offered()`).
- `PendingVoter` grants `IS_AUTHENTICATED_2FA_IN_PROGRESS` to that token alone: neither a visitor
  signed out nor one signed in opens the second factor's page.
- `SecondFactorGate` sends that visitor to the second factor's page from any other page, after the
  firewall (priority 7 on `kernel.request`) and when Security refused a page (`kernel.exception`,
  priority 2) - but the page and its check, a page `access_control` opens to everybody
  (`PUBLIC_ACCESS`) or to that token, and the sign-out.
