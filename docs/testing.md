---
title: Testing
order: 11
---

# Testing

`Omniauth\Testing\` holds what a host's tests (and a first try) need, with no library:

| Class | What it is |
|---|---|
| `TestAccount` | an account with every proof on it (hash, links, codes, version, identities) |
| `InMemoryStore` | `Accounts`, `Passwords`, `LinkTokens`, `PlainLinkTokens`, `BackupCodes`, `TrustedVersion`, `Identities` over TestAccounts; `emailCodes()` and `totpSecrets()` for the two whose methods share names |
| `RecordingSender` | sends nothing, keeps every `Message`: `last()->secret` is the link's token or the code |
| `FixedClock` | PSR-20, still until `advance($seconds)` or `set()` |
| `ArrayCache` | PSR-16 in an array, on that clock: `CacheThrottle` with no cache library |
| `StubMethod` | a first factor (a passkey, a provider) passing for `['handle' => ...]`, without its library |
| `StubSecondFactor` | a second factor (an authenticator application) enrolled for the handles given |

```php
$store = new InMemoryStore($account = new TestAccount('1', 'ringo', 'ringo@example.org', password_hash('x', PASSWORD_BCRYPT)));
$omniauth = new Omniauth($store, new Methods([new PasswordMethod($store, $store), new StubMethod(Factor::Passkey, $store)]), new ArrayState(), clock: new FixedClock());
$omniauth->verify('passkey', new Context(), ['handle' => '1'])->isAuthenticated();   // true
```
