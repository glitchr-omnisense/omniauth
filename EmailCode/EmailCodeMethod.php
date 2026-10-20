<?php

namespace Omniauth\EmailCode;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Contract\EmailCodes;
use Omniauth\Contract\Sender;
use Omniauth\Enrolled;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Message;
use Omniauth\Method\Challenge;
use Omniauth\Method\Challenging;
use Omniauth\Method\Enrolling;
use Omniauth\Method\Revocable;
use Omniauth\Method\SecondFactor;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Omniauth\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * A code sent by e-mail once a first factor passed: 6 digits (as scheb/2fa
 * draws them), good for 10 minutes and once. By Marco's decision QA5
 * (2026-10-20) the store keeps its hash, never the code: an HMAC-SHA256 keyed
 * by the host's secret and bound to the account, compared in constant time;
 * the deadline is kept in the attempt.
 *
 * begin() sends it (the second-factor page calls it, or a "send again"
 * button); verify(): ['code' => ...]. Enrolment: enrolBegin() sends a code,
 * enrolConfirm() checks it and turns the factor on.
 */
final class EmailCodeMethod implements Challenging, SecondFactor, Enrolling, Revocable
{
    public const LIFETIME = 600;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly EmailCodes $codes,
        private readonly Sender $sender,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $digits = 6,
        private readonly int $lifetime = self::LIFETIME,
        ?ClockInterface $clock = null,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('EmailCodeMethod needs a secret to key the codes\' hashes (the kernel\'s secret).');
        }
        if ($digits < 4 || $digits > 9) {
            throw new \InvalidArgumentException('A code has 4 to 9 digits.');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    public function factor(): Factor
    {
        return Factor::EmailCode;
    }

    public function enrolled(object $account): bool
    {
        return $this->codes->enabled($account);
    }

    public function begin(Attempt $attempt, Proof $proof, Context $context): Challenge
    {
        $account = $attempt->account();

        return null !== $account ? $this->send($attempt, $account) : Challenge::failed(Failure::NoAttempt);
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = $attempt->account();

        return null !== $account ? $this->check($attempt, $account, $proof) : Verdict::refused(Failure::NoAttempt);
    }

    public function enrolBegin(Attempt $enrolment, Proof $proof, Context $context): Challenge
    {
        return $this->send($enrolment, $enrolment->account() ?? throw new \LogicException('An enrolment has its account.'));
    }

    public function enrolConfirm(Attempt $enrolment, Proof $proof, Context $context): Enrolled
    {
        $account = $enrolment->account() ?? throw new \LogicException('An enrolment has its account.');
        $verdict = $this->check($enrolment, $account, $proof);
        if (!$verdict->hasPassed()) {
            return Enrolled::failed($verdict->failure ?? Failure::BadCode);
        }
        $this->codes->enable($account);

        return Enrolled::done();
    }

    public function revoke(object $account): void
    {
        $this->codes->disable($account);
        $this->codes->forget($account);
    }

    /** What the store keeps of a code. */
    public function hash(object $account, #[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $this->accounts->handle($account)."\0".$code, $this->secret);
    }

    private function send(Attempt $attempt, object $account): Challenge
    {
        $code = (string) random_int(10 ** ($this->digits - 1), 10 ** $this->digits - 1);
        $until = $this->clock->now()->add(new \DateInterval('PT'.$this->lifetime.'S'));
        $this->codes->remember($account, $this->hash($account, $code));
        $attempt->keep(Factor::EmailCode, $until->getTimestamp());
        $this->sender->send($account, new Message(Factor::EmailCode, $code, $until));

        return Challenge::sent(['until' => $until->getTimestamp()]);
    }

    private function check(Attempt $attempt, object $account, Proof $proof): Verdict
    {
        $hash = $this->codes->remembered($account);
        $until = $attempt->kept(Factor::EmailCode);
        if (null === $hash || !\is_int($until)) {
            return Verdict::expired();
        }
        if ($this->clock->now()->getTimestamp() >= $until) {
            $this->codes->forget($account);
            $attempt->take(Factor::EmailCode);

            return Verdict::expired();
        }
        $code = preg_replace('~\s+~', '', $proof->string('code')) ?? '';
        if ('' === $code || !hash_equals($hash, $this->hash($account, $code))) {
            return Verdict::refused(Failure::BadCode);
        }
        $this->codes->forget($account);
        $attempt->take(Factor::EmailCode);

        return Verdict::passed($account);
    }
}
