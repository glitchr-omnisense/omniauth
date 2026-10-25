---
title: The second-factor rule
order: 37
---

# The second-factor rule

```php
interface SecondFactorPolicy
{
    /** @return list<Factor> the second factors still due (any one suffices); none: done */
    public function required(object $account, array $passed, Context $context): array;
}
```

The default, `EnrolledSecondFactors`: an account that set up a second factor (an authenticator
application, the code by e-mail: each `Method\SecondFactor` says whether it is `enrolled()`) must
pass one of them - or a backup code, offered last - after a first factor of the list
`EnrolledSecondFactors::AFTER`; a second factor passed (a trusted device among them) is enough.

`AFTER` is `[Password, Link, OAuth]`, Marco's decisions of 2026-10-20:

- **QA1 - no second factor after a passkey**: it already proves the device and the gesture or the
  code that unlocks it. `Passkey` is not in the list.
- **QA2 - a second factor after an identity provider**: an account with its TOTP no longer signs in
  by Google without it. `OAuth` is in the list; it is the one behaviour that differs from the
  bundles by default.

A host changes the list (`new EnrolledSecondFactors($methods, [...])`) or writes its own policy:
omnibase decorates this one with its `SecurityPolicy` (a role that requires a second factor and has
none is sent to enrol; the demo account never is). Refusing to turn a factor off ("the
administrator wins") is a listener of `Event\Revoking`.

## How the default rule decides

`EnrolledSecondFactors::required($account, $passed, $context)`, with `$after` the list above:

1. none of the factors passed is in `$after` (a passkey alone, say): nothing is due;
2. a second factor was passed already (a trusted device, read by the conductor on the way): nothing
   is due;
3. the second factors mounted that the account set up (`Method\SecondFactor::enrolled()`; the
   ambient trusted device is not one) are listed in the order the methods were mounted; without
   any but backup codes, nothing is due;
4. otherwise those factors are due, any one of them, and backup codes last when the account has
   some.

```php
use Omniauth\EnrolledSecondFactors;
use Omniauth\Factor;

// A second factor after a passkey too: QA1 undone for this host.
$policy = new EnrolledSecondFactors($methods, [Factor::Password, Factor::Link, Factor::OAuth, Factor::Passkey]);
$omniauth = new Omniauth($accounts, $methods, $state, policy: $policy);
```

The conductor builds `new EnrolledSecondFactors($methods)` when no policy is given. A policy of the
host's own receives the account, the factors passed and the `Context` (the host can ask more of a
visitor from an unknown network, say) and returns the factors still due; an empty list ends the
sign-in.
