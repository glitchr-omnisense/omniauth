<?php

namespace Omniauth\Contract;

use Omniauth\Message;

/** Sends a sign-in link or a code to an account (omnibase: its Notifier; tests: Testing\RecordingSender). */
interface Sender
{
    public function send(object $account, Message $message): void;
}
