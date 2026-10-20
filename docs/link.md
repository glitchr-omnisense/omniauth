---
title: Sign-in link
order: 4
---

# Sign-in link

```php
new LinkMethod($accounts, $linkTokens, $sender, $lifetime = 900);
$omniauth->begin('link', $context, ['identifier' => 'ringo@example.org']);   // Challenge "sent", whatever the address
$omniauth->verify('link', $context, ['token' => $tokenFromTheLink]);         // the link's page, a GET from a mailbox
```

By Marco's decision QA4 (2026-10-20):

- **15 minutes** (`LinkMethod::LIFETIME`), **once** (`LinkTokens::consume()` uses it up);
- **hashed**: the token is 32 random bytes (base64url, 43 characters) sent to the account; the store
  keeps its SHA-256 (`LinkMethod::hash()`), so whoever reads the table cannot sign in with it;
- **a new link at each request** (the old "a link is still valid, nothing sent" is gone), 3 an hour
  for an address (`link.begin` in `CacheThrottle::LIMITS`);
- the same answer whether the address has an account or not, and nothing sent for one that has none.

The message (`Message`: the factor, the token as `secret`, `until`) goes to the host's `Sender`,
which builds the address of its link page. A link signs in with the host's remember-me, as
omnibase's did; it is a first factor like the password, so the second factor follows when the
account has one.

The links issued before the switch were kept in clear: a store that still holds some also
implements `PlainLinkTokens`, and they serve once, as they are, until their end.
