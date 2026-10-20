<?php

namespace Omniauth\Link;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Contract\LinkTokens;
use Omniauth\Contract\PlainLinkTokens;
use Omniauth\Contract\Sender;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Message;
use Omniauth\Method\Challenge;
use Omniauth\Method\Challenging;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Omniauth\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * A sign-in link sent to the account's address, by Marco's decision QA4
 * (2026-10-20): good for 15 minutes, once, and kept hashed (SHA-256) - the
 * store never sees its value. Each request sends a new link (the conductor
 * limits them: "link.begin", 3 an hour by default), and the answer is the
 * same whether the address has an account or not.
 *
 * begin(): ['identifier' => the address]; verify(): ['token' => the link's token].
 * The link's page signs in a GET from a mailbox; the host's remember-me follows
 * (as omnibase's link did).
 */
final class LinkMethod implements Challenging
{
    public const LIFETIME = 900;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly LinkTokens $tokens,
        private readonly Sender $sender,
        private readonly int $lifetime = self::LIFETIME,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function factor(): Factor
    {
        return Factor::Link;
    }

    public function begin(Attempt $attempt, Proof $proof, Context $context): Challenge
    {
        $identifier = $proof->string('identifier');
        $account = '' !== $identifier ? $this->accounts->byIdentifier($identifier) : null;
        if (null !== $account) {
            $value = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $until = $this->clock->now()->add(new \DateInterval('PT'.$this->lifetime.'S'));
            $this->tokens->issue($account, self::hash($value), $until);
            $this->sender->send($account, new Message(Factor::Link, $value, $until));
        }

        return Challenge::sent();
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $value = $proof->string('token');
        if ('' === $value) {
            return Verdict::refused(Failure::InvalidLink);
        }
        $now = $this->clock->now();
        $account = $this->tokens->consume(self::hash($value), $now)
            ?? ($this->tokens instanceof PlainLinkTokens ? $this->tokens->consumePlain($value, $now) : null);

        return null !== $account ? Verdict::passed($account, remember: true) : Verdict::refused(Failure::InvalidLink);
    }

    /** What the store keeps of a link's token. */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
