---
title: Events
order: 9
---

# Events

PSR-14, optional: pass any `EventDispatcherInterface` as `events:`.

| Event | When | A listener may |
|---|---|---|
| `Beginning` | before a ceremony begins (a link or a code about to go out) | `refuse($reason)` |
| `Verifying` | before a method checks a proof, after the throttle | `refuse($reason)`: omnishield (not a human), omnibase's SignInGuard (a captcha is due) |
| `SecondFactorRequired` | a first factor passed, a second is due | |
| `SignedIn` | signed in (the account, the factors passed, the context) | track the device, send "a new device", bring the account up to date |
| `SignInFailed` | any failure (the factor, the failure, the identifier, the address - never the secret) | count, log |
| `AccountRefused` | the proof was good, the account may not sign in | send "welcome back" to a disabled account |
| `Revoking` | before a factor is turned off | `refuse($reason)`: a role that requires it keeps it |
| `CredentialAdded`, `CredentialRemoved` | a factor set up (a password changed) or turned off | notify |

A refusal ends the attempt with `Failure::Refused` and the listener's reason in `Result::$reason`.
