---
title: Trusted devices
order: 7
---

# Trusted devices

```php
new TrustedDevices($accounts, $trustedVersion, $secret, $lifetime = 2592000);
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
