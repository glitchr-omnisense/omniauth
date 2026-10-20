<?php

namespace Omniauth\Contract;

/**
 * The visitor's own state between two requests: the session under Symfony
 * and Laravel, Omniauth\ArrayState in plain PHP and tests. Values are arrays
 * of scalars.
 */
interface State
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;
}
