---
title: Password
order: 31
---

# Password

```php
new PasswordMethod($accounts, $passwords, $hasher = null);   // null: NativePasswordHasher, bcrypt cost 13 - Symfony's "auto"
$omniauth->verify('password', $context, ['identifier' => 'ringo@example.org', 'password' => '...', 'remember' => true]);
```

Checked by **`symfony/password-hasher` under every host**, never by `password_verify()` alone:
Symfony prehashes a password longer than 72 bytes (or holding a NUL byte) in SHA-512 and base64
before bcrypt, so `password_verify()` - and Laravel's `Hash` - would refuse those accounts. The
same hasher says when to rehash: a hash of another cost is rewritten at sign-in
(`Passwords::update()`), and a hash of the hasher's own cost is left as it is (Laravel's default
of 12 would rehash every Symfony account on each sign-in).

Under Symfony, pass the hasher `security.yaml` builds (`PasswordHasherFactory`, "auto": a
`MigratingPasswordHasher`); the hashes it wrote yesterday verify the same.

An unknown address and a wrong password get the same answer (`bad-credentials`) in the same time:
an unknown account is checked against a hash of nothing. A banned account is refused
(`account-refused`, with its reason) only once its password is right. Five tries a minute for an
identifier from an address (`CacheThrottle::LIMITS['password']`).

A new password, from the settings: `$omniauth->enrol('password', $account)->confirm($context,
['password' => 'new', 'current' => 'old'])` - `current` is checked when it is given (leave it out
for a reset by e-mail) - and every trusted browser falls back to the second factor.

## What is checked

| Case | Answer |
|---|---|
| no `identifier`, no account for it, an account without a hash, an empty `password` | `bad-credentials`, after a verification against a hash of the word `omniauth` (drawn once per method) |
| a wrong password | `bad-credentials` |
| the right password | passed; when the hasher says `needsRehash()`, the new hash is kept (`Passwords::update()`) first |
| `remember` true in the proof | `Result::$remember` |

The account is found by `Accounts::byIdentifier()`: an address, a user name - what the store
accepts. `Accounts::check()` runs after the password passed (the conductor's step), so a banned
account answers `account-refused` only to whoever knows its password.

| Set-up step | Proof | Answer |
|---|---|---|
| `enrol('password', $account)->begin($context)` | - | `Challenge::none()` |
| `->confirm($context, $proof)` | `password` (the new one), `current` (optional) | an empty `password`, or a `current` given that is not the account's: `Enrolled::failed(bad-credentials)`; otherwise the new hash kept, `Event\CredentialAdded`, and the trusted devices fall back (`TrustedVersion::revokeAll()`) |

A password cannot be turned off through omniauth: `revoke('password', $account)` throws a
`LogicException` (`PasswordMethod` is not `Revocable`).
