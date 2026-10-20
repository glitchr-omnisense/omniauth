<?php

namespace Omniauth\Testing;

use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache in a PHP array, its lifetimes read on the clock given: what
 * CacheThrottle counts in for tests and a first try, with no cache library.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{mixed, ?int}> */
    private array $values = [];

    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->values[$key][0];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }
        $this->values[$key] = [$value, null === $ttl ? null : $this->now() + $ttl];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->values[$key])) {
            return false;
        }
        if (null !== $this->values[$key][1] && $this->values[$key][1] <= $this->now()) {
            unset($this->values[$key]);

            return false;
        }

        return true;
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
