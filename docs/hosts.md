---
title: Symfony and Laravel
order: 14
---

# Symfony and Laravel

omniauth is the same under every host; the bridges live in omnibridge (Marco's decision QA8),
where omniauth is only suggested:

- **omnibridge/symfony** (`Omnibridge\Symfony\Auth\`, step AU6): one authenticator per firewall
  over `Omniauth::verify()`, the "second factor in progress" token answering
  `IS_AUTHENTICATED_2FA_IN_PROGRESS` (scheb/2fa's name, so `access_control` does not change), the
  ceremonies' routes at the paths and under the names scheb/2fa, web-auth/webauthn-symfony-bundle
  and hwi/oauth-bundle had, the session as `State`, omniauth's `CacheThrottle` on a cache pool as
  `Throttle`, the hasher `security.yaml` sets for the user class: [Symfony](symfony.md).
- **omnibridge/laravel** (`Omnibridge\Laravel\Auth\`, step AU8): a guard and a user provider (driver
  `omniauth`) over the same conductor, the same addresses and route names, the session as `State`,
  `CacheThrottle` on a Laravel cache store, the gate answering Security's attributes
  (`ROLE_*`, `IS_AUTHENTICATED_FULLY`...): [Laravel](laravel.md).

Both read the same keys - `config/packages/omniauth.yaml` under Symfony, `config/omniauth.php`
under Laravel - so a site and its twin on one database say the same thing once each. What two
hosts of one database must share for a visitor to cross from one to the other:

| What | Why | Symfony | Laravel |
|---|---|---|---|
| the secret | it keys the e-mailed codes' hashes and signs the trusted devices' tokens | `omniauth.secret` (default `%kernel.secret%`) | `omniauth.secret` (default: `app.key`, decoded) |
| the TOTP key | the secrets sealed at rest open under it only | `totp.secret_key` | `totp.secret_key` |
| the trusted devices' cookie and firewall name | the `fwl` claim of scheb/2fa's tokens | `trusted_device.cookie`, `trusted_device.firewall` (default: the request's firewall) | `trusted_device.cookie`, `trusted_device.firewall` (default `main`) |
| the password hasher | the same hashes verify, and are not rehashed at each sign-in | `password.user_class`: the hasher `security.yaml` sets for it | `password.hasher`: `security.yaml`'s keys |
| the relying party's id | a passkey serves the hosts under its id only | `passkey.rp_id` | `passkey.rp_id` |
| the throttle's pool | both count the same tries, and the TOTP codes already used | `throttle.pool` | `throttle.pool` (a PSR-6 binding) |

A host that is neither mounts omniauth by hand, as [the installation](installation.md) shows: the
conductor over its session, its answers to the `Result`.
