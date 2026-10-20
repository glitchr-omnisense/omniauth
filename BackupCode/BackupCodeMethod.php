<?php

namespace Omniauth\BackupCode;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\BackupCodes;
use Omniauth\Enrolled;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Challenge;
use Omniauth\Method\Enrolling;
use Omniauth\Method\Revocable;
use Omniauth\Method\SecondFactor;
use Omniauth\Method\Verdict;
use Omniauth\Proof;

/**
 * Backup codes: handed out once (8 by default, "A1B2C3-D4E5F6" as omnibase
 * draws them), kept as password_hash() hashes - the very hashes omnibase's
 * user.backupCodes holds - and each good once: the one that serves is
 * removed. They stand in for another second factor; alone, they ask for none
 * (EnrolledSecondFactors).
 *
 * verify(): ['code' => ...]; enrolConfirm() draws a new set, replaces the old
 * one and returns the codes, to show once.
 */
final class BackupCodeMethod implements SecondFactor, Enrolling, Revocable
{
    public function __construct(
        private readonly BackupCodes $codes,
        private readonly int $count = 8,
    ) {
    }

    public function factor(): Factor
    {
        return Factor::BackupCode;
    }

    public function enrolled(object $account): bool
    {
        return [] !== $this->codes->hashes($account);
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = $attempt->account();
        if (null === $account) {
            return Verdict::refused(Failure::NoAttempt);
        }
        $code = strtoupper(trim($proof->string('code')));
        if ('' === $code) {
            return Verdict::refused(Failure::BadCode);
        }
        $hashes = $this->codes->hashes($account);
        foreach ($hashes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($hashes[$i]);
                $this->codes->replace($account, array_values($hashes));

                return Verdict::passed($account);
            }
        }

        return Verdict::refused(Failure::BadCode);
    }

    public function enrolBegin(Attempt $enrolment, Proof $proof, Context $context): Challenge
    {
        return Challenge::none();
    }

    public function enrolConfirm(Attempt $enrolment, Proof $proof, Context $context): Enrolled
    {
        $account = $enrolment->account() ?? throw new \LogicException('An enrolment has its account.');
        $codes = self::draw($this->count);
        $this->codes->replace($account, array_map(static fn (string $code) => password_hash($code, \PASSWORD_DEFAULT), $codes));

        return Enrolled::done($codes);
    }

    public function revoke(object $account): void
    {
        $this->codes->replace($account, []);
    }

    /** @return list<string> */
    public static function draw(int $count): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $codes[] = strtoupper(bin2hex(random_bytes(3))).'-'.strtoupper(bin2hex(random_bytes(3)));
        }

        return $codes;
    }
}
