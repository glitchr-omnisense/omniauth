---
title: Backup codes
order: 6
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
