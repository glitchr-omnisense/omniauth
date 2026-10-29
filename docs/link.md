---
title: Sign-in link
order: 32
---

# Sign-in link

```php
new LinkMethod($accounts, $linkTokens, $sender, $lifetime = 900, $clock = null);
$omniauth->begin('link', $context, ['identifier' => 'ringo@example.org']);   // Challenge "sent", whatever the address
$omniauth->verify('link', $context, ['token' => $tokenFromTheLink]);         // the link's page, a GET from a mailbox
```

By Marco's decision QA4 (2026-10-20):

- **15 minutes** (`LinkMethod::LIFETIME`), **once** (`LinkTokens::consume()` uses it up);
- **hashed**: the token is 32 random bytes (base64url, 43 characters) sent to the account; the store
  keeps its SHA-256 (`LinkMethod::hash()`), so whoever reads the table cannot sign in with it;
- **a new link at each request** (the old "a link is still valid, nothing sent" is gone), 3 an hour
  (`link.begin` in `CacheThrottle::LIMITS`), counted per address typed (lower case), whichever
  network the requests come from;
- the same answer whether the address has an account or not, and nothing sent for one that has none.

The message (`Message`: the factor, the token as `secret`, `until`) goes to the host's `Sender`,
which builds the address of its link page. A link signs in with the host's remember-me, as
omnibase's did; it is a first factor like the password, so the second factor follows when the
account has one.

The links issued before the switch were kept in clear: a store that still holds some also
implements `PlainLinkTokens`, and they serve once, as they are, until their end.

## The link's steps

| Step | Proof | Answer |
|---|---|---|
| `begin('link', ...)` | `identifier`: what the person typed | `Challenge::sent()` always; for an account found, a token drawn, `LinkTokens::issue($account, sha256, $until)` and `Sender::send($account, Message)` |
| `verify('link', ...)` | `token`: the link's token | `LinkTokens::consume(sha256, $now)`, then `PlainLinkTokens::consumePlain($token, $now)` when the store implements it; an account: passed, with the remember-me asked; none (empty, unknown, used, past its time): `invalid-link` |

The whole way, with the in-memory store (run in the [harness](harness.md) on 2026-10-25):

```php
<?php

require 'vendor/autoload.php';

use Omniauth\ArrayState;
use Omniauth\CacheThrottle;
use Omniauth\Context;
use Omniauth\Link\LinkMethod;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Testing\ArrayCache;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\RecordingSender;
use Omniauth\Testing\TestAccount;

$store = new InMemoryStore(new TestAccount('42', 'ringo', 'ringo@example.org'));
$sender = new RecordingSender();
$omniauth = new Omniauth($store, new Methods([new LinkMethod($store, $store, $sender)]), new ArrayState(), throttle: new CacheThrottle(new ArrayCache()));
$context = new Context(origin: 'https://example.org', host: 'example.org', ip: '203.0.113.7');

echo $omniauth->begin('link', $context, ['identifier' => 'nobody@example.org'])->kind, ', ', $sender->count(), " sent\n";
echo $omniauth->begin('link', $context, ['identifier' => 'ringo@example.org'])->kind, ', ', $sender->count(), " sent\n";
$token = $sender->last()->secret;                         // the link: https://example.org/login/token/<token>
echo strlen($token), ' characters, good for ', $sender->last()->until->getTimestamp() - time(), " seconds\n";

$result = $omniauth->verify('link', $context, ['token' => $token]);
echo $result->status->value, ' as ', $result->account->identifier, ', remember: ', var_export($result->remember, true), "\n";
echo $omniauth->verify('link', $context, ['token' => $token])->failure->value, " the second time\n";

foreach (range(1, 2) as $i) {
    $omniauth->begin('link', $context, ['identifier' => 'ringo@example.org']);
}
echo $omniauth->begin('link', $context, ['identifier' => 'ringo@example.org'])->failure?->value, " at the fourth link of the hour for one address\n";
echo $omniauth->begin('link', new Context(ip: '198.51.100.9'), ['identifier' => 'ringo@example.org'])->failure?->value, " from another network too\n";
```

```
sent, 0 sent
sent, 1 sent
43 characters, good for 900 seconds
authenticated as ringo, remember: true
invalid-link the second time
throttled at the fourth link of the hour for one address
throttled from another network too
```

Under Symfony the link's page is the application's route `link.route` (a GET with the token signs
in through the authenticator); under Laravel the bridge's `security_loginWithToken`
(`/login/token/{token?}`), whose POST sends the link ([Symfony](symfony.md), [Laravel](laravel.md)).
