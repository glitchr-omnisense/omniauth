---
title: Symfony and Laravel
order: 12
---

# Symfony and Laravel

omniauth is the same under every host; the bridges live in omnibridge (Marco's decision QA8),
where omniauth is only suggested:

- **omnibridge/symfony** (`Omnibridge\Symfony\Auth\`, step AU6): one authenticator per firewall
  over `Omniauth::verify()`, the "second factor in progress" token answering
  `IS_AUTHENTICATED_2FA_IN_PROGRESS` (scheb's name, so `access_control` does not change), the
  ceremonies' routes at today's paths and names, the session as `State`, the rate limiter as
  `Throttle`, the hasher of `security.yaml`.
- **omnibridge/laravel** (`Omnibridge\Laravel\Auth\`, step AU8): a guard and a user provider over the
  same conductor, the same addresses, the same route names.

Neither exists yet: until they do, a host mounts omniauth by hand as [the installation](installation.md)
shows.
