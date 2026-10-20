<?php

/**
 * Every method package: its slug (omniauth/<slug>, github.com/glitchr-studio/omniauth-<slug>),
 * its tests' namespace, and what its tests need beside it in the harness (development
 * requirements). omniauth/passkey and omniauth/totp (step AU3); omniauth/oauth to come (AU5).
 */
return [
    // The bundle's DBAL types write the passkey rows the compatibility tests compare.
    'passkey' => ['Omniauth\\Passkey\\Tests\\', ['doctrine/dbal' => '^4.0', 'web-auth/webauthn-symfony-bundle' => '^5.3']],
    'totp' => ['Omniauth\\Totp\\Tests\\'],
    // 'oauth' => ['Omniauth\\OAuth\\Tests\\'],
];
