<?php

namespace Omniauth;

use Omniauth\Contract\Accounts;
use Omniauth\Contract\SecondFactorPolicy;
use Omniauth\Contract\State;
use Omniauth\Contract\Throttle;
use Omniauth\Event\AccountRefused as AccountRefusedEvent;
use Omniauth\Event\Beginning;
use Omniauth\Event\CredentialAdded;
use Omniauth\Event\CredentialRemoved;
use Omniauth\Event\Revoking;
use Omniauth\Event\SecondFactorRequired;
use Omniauth\Event\SignedIn;
use Omniauth\Event\SignInFailed;
use Omniauth\Event\Verifying;
use Omniauth\Exception\AccountRefused;
use Omniauth\Method\Ambient;
use Omniauth\Method\Challenge;
use Omniauth\Method\Challenging;
use Omniauth\Method\Enrolling;
use Omniauth\Method\Method;
use Omniauth\Method\Methods;
use Omniauth\Method\Revocable;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The conductor: who signs in, by which factors, and what remains to pass.
 * Whatever the method, verify() does the same sequence - throttle, Verifying,
 * the method, Accounts::check(), the second-factor rule (an ambient factor
 * tried on the way), SignedIn or SignInFailed, the attempt kept or cleared -
 * and that single sequence is what makes the behaviour the same under every
 * host. The host keeps the request, the response, its session cookie (renewed
 * at each sign-in), its remember-me and its pages; it checks the form's CSRF
 * token (Omnibridge\Contract\Tokens) before calling.
 */
final class Omniauth
{
    /** Where Contract\State keeps the sign-in attempt. */
    public const ATTEMPT = 'omniauth.attempt';
    /** Where it keeps an enrolment, per factor. */
    public const ENROLMENT = 'omniauth.enrolment.';
    /** An attempt older than this (seconds) is gone. */
    public const LIFETIME = 600;

    private readonly SecondFactorPolicy $policy;
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly Methods $methods,
        private readonly State $state,
        ?SecondFactorPolicy $policy = null,
        private readonly ?Throttle $throttle = null,
        private readonly ?EventDispatcherInterface $events = null,
        ?ClockInterface $clock = null,
        private readonly int $lifetime = self::LIFETIME,
    ) {
        $this->policy = $policy ?? new EnrolledSecondFactors($methods);
        $this->clock = $clock ?? new SystemClock();
    }

    public function methods(): Methods
    {
        return $this->methods;
    }

    /** The sign-in attempt in progress (the second-factor page reads Attempt::offered()), null when none. */
    public function attempt(): ?Attempt
    {
        return $this->load(self::ATTEMPT);
    }

    /** Forgets the attempt in progress: abandoned, or signed out. */
    public function abandon(): void
    {
        $this->state->remove(self::ATTEMPT);
    }

    /**
     * Begins a ceremony: a link sent for an address, a code sent for the
     * account the first factor found, options to sign. What the method keeps
     * goes into the attempt.
     *
     * @param Proof|array<string, mixed> $proof
     */
    public function begin(Factor|string $factor, Context $context, Proof|array $proof = []): Challenge
    {
        $factor = Factor::of($factor);
        $method = $this->methods->get($factor);
        if (!$method instanceof Challenging) {
            throw new \LogicException(\sprintf('The "%s" method has nothing to begin: verify() it directly.', $factor->value));
        }
        $proof = Proof::of($proof);
        $first = Role::First === $factor->role();
        $attempt = $first ? ($this->load(self::ATTEMPT)?->restart($this->now()) ?? new Attempt($this->now())) : $this->load(self::ATTEMPT, $expired);
        if (!$first && null === $attempt?->account()) {
            return Challenge::failed(!empty($expired) ? Failure::Expired : Failure::NoAttempt);
        }
        if (!$first && !\in_array($factor, $attempt->offered(), true)) {
            return Challenge::failed(Failure::NotOffered);
        }
        $identifier = $first ? ($proof->string('identifier') ?: null) : null;
        // What a beginning sends goes to the address typed: its limit is the address's (QA4: 3 links an
        // hour for an address), whichever network asks - not one per visitor's address.
        $key = null !== $identifier ? 'identifier:'.strtolower($identifier) : $this->key($attempt, $identifier, $context);
        if (null !== $this->throttle && !$this->throttle->consume($factor->value.'.begin', $key)) {
            $this->dispatch(new SignInFailed($factor, Failure::Throttled, $context, $identifier, $attempt->account()));

            return Challenge::failed(Failure::Throttled);
        }
        $event = $this->dispatch(new Beginning($factor, $context, $attempt, $identifier));
        if (null !== $event->refusal()) {
            return Challenge::failed(Failure::Refused, $event->refusal());
        }
        $challenge = $method->begin($attempt, $proof, $context);
        $this->save(self::ATTEMPT, $attempt);

        return $challenge;
    }

    /**
     * Verifies a proof: a first factor starts a new attempt, a second one
     * goes on with the attempt in progress.
     *
     * @param Proof|array<string, mixed> $proof
     */
    public function verify(Factor|string $factor, Context $context, Proof|array $proof = []): Result
    {
        $factor = Factor::of($factor);
        $method = $this->methods->get($factor);
        $proof = Proof::of($proof);
        $first = Role::First === $factor->role();
        if ($first) {
            $attempt = $this->load(self::ATTEMPT)?->restart($this->now()) ?? new Attempt($this->now());
        } else {
            $attempt = $this->load(self::ATTEMPT, $expired);
            if (null === $attempt?->account()) {
                return $this->fail($factor, !empty($expired) ? Failure::Expired : Failure::NoAttempt, $context);
            }
            if (!\in_array($factor, $attempt->offered(), true)) {
                return $this->fail($factor, Failure::NotOffered, $context, account: $attempt->account());
            }
        }
        $identifier = $first ? ($proof->string('identifier') ?: null) : null;
        $key = $this->key($attempt, $identifier, $context);
        if (null !== $this->throttle && !$this->throttle->consume($factor->value, $key)) {
            return $this->fail($factor, Failure::Throttled, $context, $identifier, $attempt->account());
        }
        $event = $this->dispatch(new Verifying($factor, $context, $attempt, $identifier));
        if (null !== $event->refusal()) {
            return $this->fail($factor, Failure::Refused, $context, $identifier, $attempt->account(), $event->refusal());
        }

        $verdict = $method->verify($attempt, $proof, $context);
        if (null !== $verdict->identity) {
            $this->abandon();

            return new Result(Status::IdentityUnknown, passed: $attempt->passed(), identity: $verdict->identity);
        }
        if (!$verdict->hasPassed()) {
            $this->save(self::ATTEMPT, $attempt);

            return $this->fail($factor, $verdict->failure ?? Failure::BadCredentials, $context, $identifier, $attempt->account(), $verdict->reason);
        }

        $account = $verdict->account;
        try {
            $this->accounts->check($account);
        } catch (AccountRefused $refused) {
            $this->abandon();
            $this->dispatch(new AccountRefusedEvent($account, $refused->reason, $factor, $context));

            return $this->fail($factor, Failure::AccountRefused, $context, $identifier, $account, $refused->reason);
        }
        $this->throttle?->reset($factor->value, $key);
        if ($first) {
            $attempt->identify($account, $this->accounts->handle($account));
        }
        $attempt->remember(($first && $proof->bool('remember')) || $verdict->remember);
        $attempt->pass($factor);

        return $this->conclude($attempt, $context, !$first && $proof->bool('trust'));
    }

    /** Sets a factor up for an account, from its settings: begin(), then confirm(). */
    public function enrol(Factor|string $factor, object $account): Enrolment
    {
        $factor = Factor::of($factor);
        if (!$this->methods->get($factor) instanceof Enrolling) {
            throw new \LogicException(\sprintf('The "%s" method is not set up by an account.', $factor->value));
        }

        return new Enrolment($this, $factor, $account);
    }

    /**
     * @internal Enrolment::begin()
     *
     * @param Proof|array<string, mixed> $proof
     */
    public function enrolBegin(Factor $factor, object $account, Context $context, Proof|array $proof = []): Challenge
    {
        $method = $this->methods->get($factor);
        \assert($method instanceof Enrolling);
        // A beginning starts the enrolment over: a code sent again has its own ten minutes.
        $enrolment = new Attempt($this->now());
        $enrolment->identify($account, $this->accounts->handle($account));
        if (null !== $this->throttle && !$this->throttle->consume($factor->value.'.begin', $this->key($enrolment, null, $context))) {
            return Challenge::failed(Failure::Throttled);
        }
        $challenge = $method->enrolBegin($enrolment, Proof::of($proof), $context);
        $this->save(self::ENROLMENT.$factor->value, $enrolment);

        return $challenge;
    }

    /**
     * @internal Enrolment::confirm()
     *
     * @param Proof|array<string, mixed> $proof
     */
    public function enrolConfirm(Factor $factor, object $account, Context $context, Proof|array $proof = []): Enrolled
    {
        $method = $this->methods->get($factor);
        \assert($method instanceof Enrolling);
        $enrolment = $this->enrolment($factor, $account);
        $key = $this->key($enrolment, null, $context);
        if (null !== $this->throttle && !$this->throttle->consume($factor->value, $key)) {
            return Enrolled::failed(Failure::Throttled);
        }
        $enrolled = $method->enrolConfirm($enrolment, Proof::of($proof), $context);
        if (!$enrolled->isDone()) {
            $this->save(self::ENROLMENT.$factor->value, $enrolment);

            return $enrolled;
        }
        $this->state->remove(self::ENROLMENT.$factor->value);
        $this->throttle?->reset($factor->value, $key);
        if (Factor::Password === $factor) {
            // A new password: the browsers trusted with the former one fall back to the second factor.
            $this->forgetDevices($account);
        }
        $this->dispatch(new CredentialAdded($account, $factor));

        return $enrolled;
    }

    /**
     * Turns a factor off for an account (TrustedDevice: forgets every
     * browser). A listener of Revoking may refuse; turning off any other
     * factor makes the trusted devices fall back too.
     */
    public function revoke(Factor|string $factor, object $account): Enrolled
    {
        $factor = Factor::of($factor);
        $method = $this->methods->get($factor);
        if (!$method instanceof Revocable) {
            throw new \LogicException(\sprintf('The "%s" method cannot be turned off.', $factor->value));
        }
        $event = $this->dispatch(new Revoking($account, $factor));
        if (null !== $event->refusal()) {
            return Enrolled::failed(Failure::Refused, $event->refusal());
        }
        $method->revoke($account);
        if (Factor::TrustedDevice !== $factor) {
            $this->forgetDevices($account);
        }
        $this->dispatch(new CredentialRemoved($account, $factor));

        return Enrolled::done();
    }

    private function conclude(Attempt $attempt, Context $context, bool $trust): Result
    {
        $account = $attempt->account() ?? throw new \LogicException('A passed attempt has its account.');
        $required = $this->policy->required($account, $attempt->passed(), $context);
        if ($required) {
            foreach ($this->methods->ambient() as $ambient) {
                if ($ambient->verify($attempt, new Proof(), $context)->hasPassed()) {
                    $attempt->pass($ambient->factor());
                    $required = $this->policy->required($account, $attempt->passed(), $context);
                    break;
                }
            }
        }
        if ($required) {
            $attempt->offer($required);
            $this->save(self::ATTEMPT, $attempt);
            $this->dispatch(new SecondFactorRequired($account, $attempt->passed(), $required, $context));

            return new Result(Status::SecondFactorRequired, $account, $attempt->passed(), $required, remember: $attempt->remembers());
        }

        $device = null;
        if ($trust && [] !== ($ambient = $this->methods->ambient())) {
            $device = $ambient[0]->remember($account, $context);
        }
        $this->abandon();
        $this->dispatch(new SignedIn($account, $attempt->passed(), $context));

        return new Result(Status::Authenticated, $account, $attempt->passed(), remember: $attempt->remembers(), trustDevice: $device);
    }

    private function fail(Factor $factor, Failure $failure, Context $context, ?string $identifier = null, ?object $account = null, ?string $reason = null): Result
    {
        $this->dispatch(new SignInFailed($factor, $failure, $context, $identifier, $account, $reason));

        return Result::failed($failure, $reason, Failure::AccountRefused === $failure ? $account : null);
    }

    private function forgetDevices(object $account): void
    {
        foreach ($this->methods->ambient() as $ambient) {
            if ($ambient instanceof Revocable) {
                $ambient->revoke($account);
            }
        }
    }

    /**
     * The throttle's key: what the visitor typed and their address for a
     * first factor (as Symfony's login throttling), the account itself for a
     * second factor and an enrolment - whatever the address, so that a code
     * cannot be guessed from many. A first factor's beginning with an address
     * typed is keyed by the address alone (begin()).
     */
    private function key(Attempt $attempt, ?string $identifier, Context $context): string
    {
        if (null !== $attempt->handle()) {
            return 'account:'.$attempt->handle();
        }

        return strtolower((string) $identifier).'|'.($context->ip ?? '-');
    }

    private function enrolment(Factor $factor, object $account): Attempt
    {
        $handle = $this->accounts->handle($account);
        $enrolment = $this->load(self::ENROLMENT.$factor->value);
        if (null === $enrolment || $enrolment->handle() !== $handle) {
            $enrolment = new Attempt($this->now());
        }
        $enrolment->identify($account, $handle);

        return $enrolment;
    }

    private function load(string $key, ?bool &$expired = null): ?Attempt
    {
        $expired = false;
        $attempt = Attempt::fromArray($this->state->get($key));
        if (null === $attempt) {
            return null;
        }
        if ($this->now() - $attempt->startedAt() >= $this->lifetime) {
            $this->state->remove($key);
            $expired = true;

            return null;
        }
        if (null !== $attempt->handle()) {
            $account = $this->accounts->byHandle($attempt->handle());
            if (null === $account) {
                $this->state->remove($key);

                return null;
            }
            $attempt->identify($account, $attempt->handle());
        }

        return $attempt;
    }

    private function save(string $key, Attempt $attempt): void
    {
        $this->state->set($key, $attempt->toArray());
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    private function dispatch(object $event): object
    {
        $this->events?->dispatch($event);

        return $event;
    }
}
