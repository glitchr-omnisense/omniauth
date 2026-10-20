<?php

namespace Omniauth;

/**
 * What the host knows of the request, with no object of its framework: the
 * same values under Symfony, Laravel and plain PHP.
 */
final readonly class Context
{
    public function __construct(
        /** https://lesringostars.localhost:8443: WebAuthn compares the origin. */
        public string $origin = '',
        /** The relying party's identifier depends on it. */
        public string $host = '',
        /** Throttling keys, devices. */
        public ?string $ip = null,
        public ?string $userAgent = null,
        /** The trusted_device cookie, as the host read it. */
        public ?string $trustedDevice = null,
        /** The trusted-device tokens' "fwl" claim (scheb/2fa's firewall name). */
        public string $firewall = 'main',
    ) {
    }
}
