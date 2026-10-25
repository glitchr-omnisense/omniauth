---
title: Backup codes
order: 34
---

# Backup codes

```php
new BackupCodeMethod($backupCodes, $count = 8);
$codes = $omniauth->enrol('backup-code', $account)->confirm($context)->secrets;   // show them once
$omniauth->verify('backup-code', $context, ['code' => 'A1B2C3-D4E5F6']);
```

Eight codes, `A1B2C3-D4E5F6` as omnibase draws them, kept as `password_hash()` hashes - the very
hashes omnibase's `user.backupCodes` holds, read as they are. Each serves once: the one that
matches is removed. They stand in for another second factor and are offered after it; alone, they
ask for none (as in scheb/2fa). A new set replaces the former; `revoke('backup-code', $account)`
clears them.

## The backup codes' steps

| Step | Answer |
|---|---|
| `verify('backup-code', ...)` with `code` | trimmed and upper-cased; empty: `bad-code`; compared with `password_verify()` against each hash in turn; the one that matches is removed (`BackupCodes::replace()` with the others) and passes; none: `bad-code` |
| `enrol('backup-code', $account)->begin()` | `Challenge::none()` |
| `->confirm($context)` | a new set: `$count` codes `XXXXXX-XXXXXX` (6 upper-case hexadecimal digits, a hyphen, 6 more: `BackupCodeMethod::draw()`), hashed with `password_hash(PASSWORD_DEFAULT)` in place of the former set; `Enrolled::$secrets` holds them, to show once |
| `revoke('backup-code', $account)` | `BackupCodes::replace($account, [])`; the trusted devices fall back |

`enrolled()` is true while the account has at least one hash. Backup codes alone ask no second
factor: the rule offers them after another second factor the account set up, and last
([The second-factor rule](second-factor.md)). Each code costs one `password_verify()` (bcrypt) per
hash tried: up to `$count` of them for a wrong code.
