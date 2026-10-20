<?php

namespace Omniauth\Contract;

/**
 * Limits the tries: Symfony's RateLimiterFactory, Laravel's RateLimiter,
 * Omniauth\CacheThrottle on any PSR-6 or PSR-16 cache.
 *
 * A scope names what is limited ("password", "email-code", "link.begin"...),
 * a key whom (an identifier and an address, an account).
 */
interface Throttle
{
    /** Counts one try; false when the limit is reached (nothing is checked then). */
    public function consume(string $scope, string $key): bool;

    /** Forgets the tries: after a success. */
    public function reset(string $scope, string $key): void;
}
