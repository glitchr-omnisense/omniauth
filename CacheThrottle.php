<?php

namespace Omniauth;

use Omniauth\Contract\Throttle;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A fixed window per scope and key, counted in any PSR-6 pool or PSR-16
 * cache: plain PHP and tests. Under Symfony and Laravel, their rate limiters
 * answer the same contract.
 *
 * Read then written, not atomic: two requests at the same instant may both
 * pass the last try. A host that needs it exact uses its rate limiter.
 */
final class CacheThrottle implements Throttle
{
    /**
     * Tries per window, in seconds. "*": any other scope. "<factor>.begin"
     * limits what sends something (a link, a code).
     */
    public const LIMITS = [
        'password' => [5, 60],
        'link.begin' => [3, 3600],
        'email-code' => [5, 900],
        'email-code.begin' => [3, 900],
        'backup-code' => [5, 900],
        'totp' => [5, 900],
        '*' => [5, 60],
    ];

    /** @var array<string, array{int, int}> */
    private readonly array $limits;
    private readonly ClockInterface $clock;

    /** @param array<string, array{int, int}> $limits replace the defaults scope by scope */
    public function __construct(
        private readonly CacheItemPoolInterface|CacheInterface $cache,
        array $limits = [],
        ?ClockInterface $clock = null,
        private readonly string $prefix = 'omniauth.throttle.',
    ) {
        $this->limits = $limits + self::LIMITS;
        $this->clock = $clock ?? new SystemClock();
    }

    public function consume(string $scope, string $key): bool
    {
        [$limit, $period] = $this->limits[$scope] ?? $this->limits['*'];
        $id = $this->id($scope, $key);
        $now = $this->clock->now()->getTimestamp();
        [$count, $until] = $this->read($id);
        if ($until <= $now) {
            [$count, $until] = [0, $now + $period];
        }
        if ($count >= $limit) {
            return false;
        }
        $this->write($id, [$count + 1, $until], $until - $now);

        return true;
    }

    public function reset(string $scope, string $key): void
    {
        $id = $this->id($scope, $key);
        $this->cache instanceof CacheItemPoolInterface ? $this->cache->deleteItem($id) : $this->cache->delete($id);
    }

    /** PSR-6 and PSR-16 reserve {}()/\@: a hash keeps keys of any identifier valid. */
    private function id(string $scope, string $key): string
    {
        return $this->prefix.hash('sha256', $scope."\0".$key);
    }

    /** @return array{int, int} */
    private function read(string $id): array
    {
        $value = $this->cache instanceof CacheItemPoolInterface ? $this->cache->getItem($id)->get() : $this->cache->get($id);

        return \is_array($value) && \is_int($value[0] ?? null) && \is_int($value[1] ?? null) ? [$value[0], $value[1]] : [0, 0];
    }

    /** @param array{int, int} $value */
    private function write(string $id, array $value, int $ttl): void
    {
        $ttl = max(1, $ttl);
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->save($this->cache->getItem($id)->set($value)->expiresAfter($ttl));
        } else {
            $this->cache->set($id, $value, $ttl);
        }
    }
}
