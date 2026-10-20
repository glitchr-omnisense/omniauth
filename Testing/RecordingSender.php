<?php

namespace Omniauth\Testing;

use Omniauth\Contract\Sender;
use Omniauth\Message;

/** Sends nothing, keeps every message: the link or the code a test reads back. */
final class RecordingSender implements Sender
{
    /** @var list<array{object, Message}> */
    public array $sent = [];

    public function send(object $account, Message $message): void
    {
        $this->sent[] = [$account, $message];
    }

    public function last(): ?Message
    {
        return [] !== $this->sent ? $this->sent[\count($this->sent) - 1][1] : null;
    }

    public function count(): int
    {
        return \count($this->sent);
    }
}
