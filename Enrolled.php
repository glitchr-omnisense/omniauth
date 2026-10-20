<?php

namespace Omniauth;

/** What setting a factor up ended with; the secrets to show once (backup codes) when it worked. */
final readonly class Enrolled
{
    /** @param list<string> $secrets */
    public function __construct(
        public ?Failure $failure = null,
        public array $secrets = [],
        public ?string $reason = null,
    ) {
    }

    public static function done(array $secrets = []): self
    {
        return new self(null, array_values($secrets));
    }

    public static function failed(Failure $failure, ?string $reason = null): self
    {
        return new self($failure, [], $reason);
    }

    public function isDone(): bool
    {
        return null === $this->failure;
    }
}
