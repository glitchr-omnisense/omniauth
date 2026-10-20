<?php

namespace Omniauth;

use Omniauth\Contract\State;

/**
 * The visitor's state in a PHP array: plain PHP (hand it $_SESSION by
 * reference, or keep the array yourself) and tests. Symfony and Laravel keep
 * it in their session (omnibridge/symfony, omnibridge/laravel).
 */
final class ArrayState implements State
{
    /** @param array<string, mixed> $values */
    public function __construct(private array &$values = [])
    {
    }

    /** Over $_SESSION (session_start() first): the attempt lives as long as the PHP session. */
    public static function session(): self
    {
        if (\PHP_SESSION_ACTIVE !== session_status()) {
            throw new \LogicException('ArrayState::session() needs session_start() first.');
        }

        return new self($_SESSION);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
