---
title: Code by e-mail
order: 33
---

# Code by e-mail

```php
new EmailCodeMethod($accounts, $emailCodes, $sender, $secret, $digits = 6, $lifetime = 600, $clock = null);
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

## The code's steps

| Step | Answer |
|---|---|
| `begin('email-code', ...)` (the second factor's page, "send again") | a code drawn (`random_int()` between 10^(digits-1) and 10^digits - 1: never a leading zero), its hash kept (`EmailCodes::remember()`), its deadline kept in the attempt, `Sender::send($account, Message)`; `Challenge::sent(['until' => <timestamp>])` |
| `verify('email-code', ...)` with `code` | spaces removed; no code kept or no deadline in the attempt: `expired`; past the deadline: `expired`, the hash forgotten; not the code: `bad-code` (the code stays good until its deadline); the code: passed, the hash forgotten (once) |
| `enrol('email-code', $account)->begin()` / `->confirm(['code' => ...])` | the same code sent and checked in the enrolment's own attempt; once checked, `EmailCodes::enable()` |
| `revoke('email-code', $account)` | `EmailCodes::disable()` and `forget()`; the trusted devices fall back |

`enrolled()` is `EmailCodes::enabled()`. The constructor refuses an empty secret and digits outside
4 to 9 (`InvalidArgumentException`). `hash($account, $code)` is
`hash_hmac('sha256', handle."\0".code, $secret)`: a host that sent a code under another secret
(the Symfony site's `kernel.secret` and the twin's) must share it for the code to be good on both.

The code expires after `$lifetime` seconds, but the attempt that holds its deadline expires after
the conductor's lifetime (600 seconds by default) whichever comes first.
