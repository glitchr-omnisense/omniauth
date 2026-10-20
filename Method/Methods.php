<?php

namespace Omniauth\Method;

use Omniauth\Exception\UnknownMethod;
use Omniauth\Factor;

/** The methods a host mounted, one per factor. */
final class Methods implements \IteratorAggregate, \Countable
{
    /** @var array<string, Method> */
    private array $methods = [];

    /** @param iterable<Method> $methods */
    public function __construct(iterable $methods = [])
    {
        foreach ($methods as $method) {
            $this->add($method);
        }
    }

    public function add(Method $method): void
    {
        $this->methods[$method->factor()->value] = $method;
    }

    public function has(Factor|string $factor): bool
    {
        return isset($this->methods[$factor instanceof Factor ? $factor->value : $factor]);
    }

    /** @throws UnknownMethod */
    public function get(Factor|string $factor): Method
    {
        return $this->methods[$factor instanceof Factor ? $factor->value : $factor] ?? throw UnknownMethod::for($factor, array_keys($this->methods));
    }

    /** @return list<SecondFactor> the second factors an account sets up (not the ambient ones) */
    public function secondFactors(): array
    {
        return array_values(array_filter($this->methods, static fn (Method $m) => $m instanceof SecondFactor && !$m instanceof Ambient));
    }

    /** @return list<Ambient> */
    public function ambient(): array
    {
        return array_values(array_filter($this->methods, static fn (Method $m) => $m instanceof Ambient));
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->methods);
    }

    public function count(): int
    {
        return \count($this->methods);
    }
}
