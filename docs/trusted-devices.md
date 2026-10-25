---
title: Trusted devices
order: 35
---

## Trusted devices

```php
new TrustedDevices($accounts, $trustedVersion, $secret, $lifetime = 2592000, $clock = null);
$result = $omniauth->verify('email-code', $context, ['code' => '...', 'trust' => true]);
setcookie(TrustedDevices::COOKIE, $result->trustDevice, ...);      // "trusted_device", 30 days
// next time: new Context(..., trustedDevice: $_COOKIE['trusted_device'] ?? null)
```

A second factor already passed on this browser. The conductor tries it by itself when a second
factor is required (`Method\Ambient`): a valid token passes as `Factor::TrustedDevice` and no code
is asked. After a second factor passed with `trust`, `Result::$trustDevice` is the cookie's new
value.

**scheb/2fa's format, byte for byte**, so the browsers trusted before the switch stay trusted:
JWTs signed HS256 with the host's secret (Symfony's `kernel.secret`), claims `iat`, `exp` (with
their microseconds when they have some, as lcobucci/jwt writes them), `usr` (the account's
`Accounts::identifier()`), `fwl` (`Context::$firewall`), `vsn` (the account's `TrustedVersion`),
several tokens joined by `;` - the other accounts' and firewalls' kept, this one's replaced, the
invalid and expired dropped. Written with PHP's HMAC, with no JWT library; read as scheb reads
them (signature, `alg`, `iat <= now < exp`). The key needs 32 bytes, as lcobucci/jwt requires.

A token counts while its version is the account's: turning a second factor off, setting a new
password or `revoke('trusted-device', $account)` raises it, and every browser falls back.

The proof: `Tests/TrustedDevicesTest` compares omniauth's tokens with scheb's `JwtTokenEncoder`'s
for the same input (recorded, and live in the harness, where scheb/2fa is a development
dependency), and each reads the other's.

### The tokens' checks

| | |
|---|---|
| `verify()` (the conductor's, on its own) | passes when `Context::$trustedDevice` holds a valid token whose `usr` is the account's identifier, `fwl` the context's firewall and `vsn` the account's version; otherwise `not-enrolled` |
| `remember($account, $context)` | the cookie's new value: the valid tokens of other accounts or firewalls kept, this account's and firewall's replaced by one issued now, until now + `$lifetime` |
| `revoke($account)` | `TrustedVersion::revokeAll()`: every browser falls back |
| `trusts($account, $cookie, $firewall = 'main')` | the same check as `verify()`, for a host's own use |
| `encode($username, $firewall, $version, $issuedAt, $until)`, `decode($token)` | one token, written and read as scheb/2fa's `JwtTokenEncoder` does; `decode()` gives the claims, or null for a token not signed with the key, not HS256 (or encrypted), not yet valid (`iat`, `nbf`) or expired (`exp`) |

`TrustedDevices::COOKIE` is `trusted_device`, `TrustedDevices::LIFETIME` 2592000 seconds (30
days). The constructor refuses a secret shorter than 32 bytes (`InvalidArgumentException`). The
cookie itself - its name, path, flags and expiry - is the host's: the bridges set it on `/`, HTTP
only, `SameSite=Lax`, secure on HTTPS, for the configured lifetime. Trusted devices are offered on
the second factor's page as "trust this browser" when the method is mounted
(`displayTrustedOption`); the proof's `trust` asks it.
