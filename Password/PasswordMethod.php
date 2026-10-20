<?php

namespace Omniauth\Password;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Contract\Passwords;
use Omniauth\Enrolled;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Challenge;
use Omniauth\Method\Enrolling;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * The password, checked by symfony/password-hasher under every host - never
 * by password_verify() alone: Symfony prehashes a password longer than 72
 * bytes (or holding a NUL byte) before bcrypt, so password_verify(), and
 * Laravel's Hash with it, would refuse those accounts. The same hasher says
 * when to rehash (cost 13 here, 12 by Laravel's default: without it, each
 * sign-in on the other host would rehash).
 *
 * Proof: ['identifier' => ..., 'password' => ...]; an enrolment (a new
 * password): ['password' => ..., 'current' => ...], 'current' checked when
 * given.
 */
final class PasswordMethod implements Enrolling
{
    private ?string $dummy = null;
    private readonly PasswordHasherInterface $hasher;

    /** @param PasswordHasherInterface|null $hasher the one security.yaml sets; by default bcrypt, cost 13, Symfony's "auto" */
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Passwords $passwords,
        ?PasswordHasherInterface $hasher = null,
    ) {
        $this->hasher = $hasher ?? new NativePasswordHasher();
    }

    public function factor(): Factor
    {
        return Factor::Password;
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $identifier = $proof->string('identifier');
        $password = $proof->string('password');
        $account = '' !== $identifier ? $this->accounts->byIdentifier($identifier) : null;
        $hash = null !== $account ? $this->passwords->hash($account) : null;
        if (null === $account || null === $hash || '' === $password) {
            // As long to answer for an unknown address as for a wrong password.
            $this->hasher->verify($this->dummy ??= $this->hasher->hash('omniauth'), $password);

            return Verdict::refused(Failure::BadCredentials);
        }
        if (!$this->hasher->verify($hash, $password)) {
            return Verdict::refused(Failure::BadCredentials);
        }
        if ($this->hasher->needsRehash($hash)) {
            $this->passwords->update($account, $this->hasher->hash($password));
        }

        return Verdict::passed($account);
    }

    public function enrolBegin(Attempt $enrolment, Proof $proof, Context $context): Challenge
    {
        return Challenge::none();
    }

    public function enrolConfirm(Attempt $enrolment, Proof $proof, Context $context): Enrolled
    {
        $account = $enrolment->account() ?? throw new \LogicException('An enrolment has its account.');
        $password = $proof->string('password');
        if ('' === $password) {
            return Enrolled::failed(Failure::BadCredentials);
        }
        if ($proof->has('current') && (null === ($hash = $this->passwords->hash($account)) || !$this->hasher->verify($hash, $proof->string('current')))) {
            return Enrolled::failed(Failure::BadCredentials);
        }
        $this->passwords->update($account, $this->hasher->hash($password));

        return Enrolled::done();
    }
}
