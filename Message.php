<?php

namespace Omniauth;

/**
 * What a method asks the host to send to an account: a sign-in link, a code.
 * The host's Sender renders it (omnibase: its Notifier and the
 * "@Base/security/email/*" templates).
 */
final readonly class Message
{
    public function __construct(
        public Factor $factor,
        /** Link: the link's token; email-code: the code. Never logged, never stored as is. */
        public string $secret,
        public \DateTimeImmutable $until,
    ) {
    }
}
