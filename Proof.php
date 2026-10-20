<?php

namespace Omniauth;

/**
 * What the visitor sent: ['identifier' => ..., 'password' => ...],
 * ['code' => ...], ['token' => ...], a WebAuthn assertion's JSON under
 * 'json', an OAuth return's 'code' and 'state'. Two keys mean the same to
 * every method: 'remember' (the host sets its remember-me) and 'trust' (a
 * trusted-device cookie after a second factor).
 */
final readonly class Proof
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /** @param self|array<string, mixed> $proof */
    public static function of(self|array $proof): self
    {
        return $proof instanceof self ? $proof : new self($proof);
    }

    /** The value as a string; '' when it is missing or is not a scalar. */
    public function string(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }

    public function bool(string $key): bool
    {
        return filter_var($this->values[$key] ?? false, \FILTER_VALIDATE_BOOL);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /** The JSON a browser posted (a WebAuthn assertion): the 'json' key, or every value encoded. */
    public function json(): string
    {
        return \is_string($this->values['json'] ?? null) ? $this->values['json'] : json_encode($this->values, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
