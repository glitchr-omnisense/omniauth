<?php

namespace Omniauth;

/**
 * One visitor's attempt to sign in, kept by the host between two requests
 * (Contract\State): the account found by the first factor, the factors
 * passed, the second factors offered, and what a ceremony keeps between its
 * beginning and its verification (a challenge, a code's deadline) - never in
 * the response.
 *
 * It holds the account's handle, not the account: the conductor loads the
 * account again (Accounts::byHandle()) each time it reads the attempt back.
 */
final class Attempt
{
    private ?object $account = null;

    /**
     * @param list<Factor>                                     $passed
     * @param list<Factor>                                     $offered
     * @param array<string, scalar|array<array-key, mixed>|null> $kept
     */
    public function __construct(
        private readonly int $startedAt,
        private ?string $handle = null,
        private array $passed = [],
        private array $offered = [],
        private array $kept = [],
        private bool $remember = false,
    ) {
    }

    public function startedAt(): int
    {
        return $this->startedAt;
    }

    /** The account the first factor found, or the account an enrolment is for. */
    public function account(): ?object
    {
        return $this->account;
    }

    public function handle(): ?string
    {
        return $this->handle;
    }

    /** @internal the conductor's: the account found, or loaded back from its handle */
    public function identify(object $account, string $handle): void
    {
        $this->account = $account;
        $this->handle = $handle;
    }

    /** @return list<Factor> */
    public function passed(): array
    {
        return $this->passed;
    }

    public function hasPassed(Factor $factor): bool
    {
        return \in_array($factor, $this->passed, true);
    }

    /** @internal the conductor's */
    public function pass(Factor $factor): void
    {
        if (!$this->hasPassed($factor)) {
            $this->passed[] = $factor;
        }
    }

    /** @return list<Factor> */
    public function offered(): array
    {
        return $this->offered;
    }

    /**
     * @internal the conductor's
     *
     * @param list<Factor> $factors
     */
    public function offer(array $factors): void
    {
        $this->offered = array_values($factors);
    }

    public function remembers(): bool
    {
        return $this->remember;
    }

    /** @internal the conductor's: the visitor ticked "remember me" at the first factor */
    public function remember(bool $remember): void
    {
        $this->remember = $this->remember || $remember;
    }

    /** Keeps what a ceremony needs at its verification (scalars and arrays: the attempt is serialised). */
    public function keep(Factor|string $key, string|int|float|bool|array|null $value): void
    {
        $this->kept[$key instanceof Factor ? $key->value : $key] = $value;
    }

    public function kept(Factor|string $key): string|int|float|bool|array|null
    {
        return $this->kept[$key instanceof Factor ? $key->value : $key] ?? null;
    }

    /** Reads what was kept and forgets it: a challenge serves once. */
    public function take(Factor|string $key): string|int|float|bool|array|null
    {
        $key = $key instanceof Factor ? $key->value : $key;
        $value = $this->kept[$key] ?? null;
        unset($this->kept[$key]);

        return $value;
    }

    /** A new attempt that keeps only what ceremonies kept (a challenge begun before this first factor). */
    public function restart(int $now): self
    {
        return new self($now, kept: $this->kept);
    }

    /** @return array<string, mixed> what Contract\State keeps */
    public function toArray(): array
    {
        return [
            'started' => $this->startedAt,
            'handle' => $this->handle,
            'passed' => array_map(static fn (Factor $f) => $f->value, $this->passed),
            'offered' => array_map(static fn (Factor $f) => $f->value, $this->offered),
            'kept' => $this->kept,
            'remember' => $this->remember,
        ];
    }

    /** @param mixed $data what Contract\State gave back: null (or anything malformed) is no attempt */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || !\is_int($data['started'] ?? null)) {
            return null;
        }
        $factors = static fn (mixed $list) => array_values(array_filter(array_map(static fn (mixed $f) => \is_string($f) ? Factor::tryFrom($f) : null, \is_array($list) ? $list : [])));

        return new self(
            $data['started'],
            \is_string($data['handle'] ?? null) ? $data['handle'] : null,
            $factors($data['passed'] ?? []),
            $factors($data['offered'] ?? []),
            \is_array($data['kept'] ?? null) ? $data['kept'] : [],
            true === ($data['remember'] ?? false),
        );
    }
}
