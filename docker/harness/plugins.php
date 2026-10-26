<?php

/**
 * Every method package: its slug (omniauth/<slug>, github.com/glitchr-omnisense/omniauth-<slug>),
 * its tests' namespace, and what its tests need beside it in the harness (development
 * requirements). omniauth/webauthn and omniauth/otp (step AU3, formerly omniauth/passkey and
 * omniauth/totp), omniauth/google (one package per provider; the mechanics they share,
 * formerly omniauth/oauth, are the core's Omniauth\OAuth\ since 2026-10-23).
 */
return [
    // The bundle's DBAL types write the passkey rows the compatibility tests compare.
    'webauthn' => ['Omniauth\\WebAuthn\\Tests\\', ['doctrine/dbal' => '^4.0', 'web-auth/webauthn-symfony-bundle' => '^5.3']],
    'otp' => ['Omniauth\\Otp\\Tests\\'],
    'google' => ['Omniauth\\Google\\Tests\\'],
];
