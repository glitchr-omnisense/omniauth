<?php

/**
 * Every method package: its slug (omniauth/<slug>, github.com/glitchr-studio/omniauth-<slug>),
 * its tests' namespace, and what its tests need beside it in the harness (development
 * requirements). omniauth/passkey and omniauth/totp (step AU3), omniauth/oauth (AU5),
 * omniauth/google (its provider, out of omniauth/oauth: one package per provider).
 */
return [
    // The bundle's DBAL types write the passkey rows the compatibility tests compare.
    'passkey' => ['Omniauth\\Passkey\\Tests\\', ['doctrine/dbal' => '^4.0', 'web-auth/webauthn-symfony-bundle' => '^5.3']],
    'totp' => ['Omniauth\\Totp\\Tests\\'],
    'oauth' => ['Omniauth\\OAuth\\Tests\\'],
    'google' => ['Omniauth\\Google\\Tests\\'],
];
