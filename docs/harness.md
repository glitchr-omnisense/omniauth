---
title: The Docker harness
order: 12
---

# The Docker harness

`docker/` runs this package with every `omniauth/*` method package - from GitHub (branch 1.x), or
from the checkouts beside this one when `OMNIAUTH_PLUGINS=../..` is set in `docker/.env` - on PHP
8.4, with scheb/2fa installed beside them as a development dependency (the trusted-device tokens
are compared with its encoder's, omniauth/totp's codes with its TOTP authenticator's), and what each
method package's tests need beside it (`docker/harness/plugins.php`: doctrine/dbal and
web-auth/webauthn-symfony-bundle for omniauth/passkey's comparison with the bundle's records).
Nothing runs on the host.

```sh
cd docker && cp .env.dist .env       # the checkouts of the workspace
docker compose run --rm omniauth test     # every package's tests
docker compose run --rm omniauth bare     # plain PHP: a whole sign-in by hand, and what PHP loaded
docker compose down -v
```

`bare` builds everything by hand on the in-memory stores: a password alone; a password, a code by
e-mail and "trust this browser", then the same account on that browser with no code; a backup code;
a link, then the same link again; a password of 102 bytes hashed by Symfony; six wrong passwords.
`Tests/BareTest` runs it, and loads every class of the package, in PHP processes of their own, and
checks that nothing is loaded but omniauth, the PSR interfaces and symfony/password-hasher - not a
class of a framework, of scheb/2fa or of an application, though the harness has them installed.
