<?php

namespace Omniauth\Method;

use Omniauth\Failure;

/** What the host gives back after a beginning: JSON for a script, an address to go to, "it was sent", or why not. */
final readonly class Challenge
{
    public const NONE = 'none';
    public const JSON = 'json';
    public const REDIRECT = 'redirect';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    /** @param array<string, mixed> $data */
    private function __construct(
        public string $kind,
        public ?string $payload = null,
        public array $data = [],
        public ?Failure $failure = null,
        public ?string $reason = null,
    ) {
    }

    /** Nothing to give: the visitor goes on to the next step. */
    public static function none(array $data = []): self
    {
        return new self(self::NONE, null, $data);
    }

    /** Options for a script (WebAuthn's), as they are. */
    public static function json(string $json): self
    {
        return new self(self::JSON, $json);
    }

    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, $url);
    }

    /** A link or a code went out - or the address has no account: the same answer for both. */
    public static function sent(array $data = []): self
    {
        return new self(self::SENT, null, $data);
    }

    public static function failed(Failure $failure, ?string $reason = null): self
    {
        return new self(self::FAILED, null, [], $failure, $reason);
    }

    public function hasFailed(): bool
    {
        return self::FAILED === $this->kind;
    }
}
