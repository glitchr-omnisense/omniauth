---
title: Usage policies
order: 85
---

# Usage policies

omnisense/contracts gives every omni family the same way to say what a provider's terms allow: a
`policy.json` in the provider's package (its limits, the rights over what it returns, what a
request must carry, where the terms were read and when), named in its `composer.json`
(`extra.omnisense.policy`), exported with every other policy by `vendor/bin/omnisense-policies`.

In omniauth, only an identity provider calls a third party:

| Package | Calls | Its policy |
|---|---|---|
| omniauth/google | Google's authorization address, token endpoint and userinfo | Google's terms: no published limit (`rateLimits: null`), user data for user-facing features only, never transferred (User Data Policy), a privacy policy, the Sign in with Google branding guidelines; `GoogleProvider::usagePolicy()` gives it |
| omniauth/otp | nobody: the codes are checked on the site's server | no third party, no limit; spomky-labs/otphp's licence (MIT) |
| omniauth/webauthn | nobody: attestation "none", no metadata service | no third party, no limit; web-auth/webauthn-lib's licence (MIT) |
| the core's methods (password, link, code by e-mail, backup codes, trusted devices) | nobody but the host's `Sender` (the site's own mailer) | none declared: the site's mailer is the site's |

## No limit is ever counted against a sign-in

omniauth guards no sign-in with a usage policy, and that is on purpose:

- A limit counted for the whole application would let anyone who exhausts it refuse every user's
  sign-in. The tries a visitor may make are the `Throttle`'s, counted per scope and per account or
  address (`CacheThrottle::LIMITS`), an authentication rule this page does not change.
- A provider that refuses (a 429 included) fails the sign-in closed: `ProviderError` with
  `exchange-failed`, which `OAuthMethod` answers as `bad-credentials` - the same answer whether an
  account exists or not. No wait is kept from it: one visitor's refusal does not hold back the next
  visitor's sign-in (omniauth/google's `PolicyTest`).
- A `ProviderError`'s traces, and those of league's or Guzzle's exception under it, carry no
  arguments: neither the code, the PKCE verifier nor the client's secret reach a log.

A site that wants to show the providers' terms (a credits or privacy page) reads
`$provider->usagePolicy()` on the providers that declare one (`DeclaresUsagePolicyInterface`).
