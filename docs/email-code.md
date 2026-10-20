---
title: Code by e-mail
order: 5
---

# Code by e-mail

```php
new EmailCodeMethod($accounts, $emailCodes, $sender, $secret, $digits = 6, $lifetime = 600);
$omniauth->begin('email-code', $context);                       // the second-factor page, or "send again"
$omniauth->verify('email-code', $context, ['code' => '481516', 'trust' => true]);
```

A second factor: offered once a first factor passed for an account that turned it on
(`EmailCodes::enabled()`). Six digits drawn as scheb/2fa draws them, good for 10 minutes and once.

By Marco's decision QA5 (2026-10-20) the code is **kept hashed**: an HMAC-SHA256 keyed by the
host's secret and bound to the account (`EmailCodeMethod::hash()`), compared in constant time; the
deadline is kept in the attempt. Five tries per account in 15 minutes, whatever the address
(`email-code`), 3 codes sent in 15 minutes (`email-code.begin`).

Turning it on, from the settings: `enrol('email-code', $account)->begin($context)` sends a code,
`->confirm($context, ['code' => ...])` checks it and turns the factor on. Off: `revoke('email-code',
$account)` - the trusted browsers fall back with it.
