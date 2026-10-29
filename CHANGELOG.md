# Changelog

## [Unreleased]

### Fixed

- A first factor's beginning with an address typed (the sign-in link, `link.begin`) is throttled
  per address alone, whatever the visitor's network address: 3 links an hour for a mailbox, as
  decision QA4 says. It was keyed by the address and the visitor's IP, so each other network got 3
  more links an hour to the same mailbox.
