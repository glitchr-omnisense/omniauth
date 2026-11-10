# Changelog

## [Unreleased]

### Changed

- omnisense/omniauth requires omnisense/contracts, the kernel of the omni families:
  `Exception\OmniauthException` extends its `ExceptionInterface` (one catch holds every family's
  failures); nothing else of the public API changes. The Throttle, the providers' `Providers` and
  `ProviderFactory` stay omniauth's own: the Throttle is an authentication rule, not a usage
  policy, and the providers are not the kernel's registry of providers.
- `LeagueProvider`: a refused exchange's `ProviderError` and league's or Guzzle's exception under
  it keep no arguments in their traces (`Redaction::withoutArguments()`): neither the code, the PKCE
  verifier nor the client's secret reach a log. The sign-in is refused exactly as before.
- docs/policies.md: which packages call a third party, their usage policies, and why no limit is
  ever counted against a sign-in. The harness installs omnisense/contracts from its checkout
  (`OMNISENSE_CONTRACTS`) or GitHub, runs PHPStan (`phpstan`) and names its entrypoint.

### Fixed

- A first factor's beginning with an address typed (the sign-in link, `link.begin`) is throttled
  per address alone, whatever the visitor's network address: 3 links an hour for a mailbox, as
  decision QA4 says. It was keyed by the address and the visitor's IP, so each other network got 3
  more links an hour to the same mailbox.
