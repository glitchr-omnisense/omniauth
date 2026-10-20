<?php

namespace Omniauth\Tests;

use Omniauth\ArrayState;
use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Event\Beginning;
use Omniauth\Event\SignedIn;
use Omniauth\Event\SignInFailed;
use Omniauth\Event\Verifying;
use Omniauth\Exception\UnknownMethod;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Method;
use Omniauth\Method\Methods;
use Omniauth\Method\Verdict;
use Omniauth\Omniauth;
use Omniauth\PendingIdentity;
use Omniauth\Proof;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/** The conductor's one sequence, whatever the method. */
final class OmniauthTest extends TestCase
{
    public function testTheAttemptCrossesRequestsAsArraysAlone(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());

        // The next request: the session serialised, a new conductor over it.
        $session = json_decode(json_encode($kit->session, \JSON_THROW_ON_ERROR), true);
        self::assertSame(['started', 'handle', 'passed', 'offered', 'kept', 'remember'], array_keys($session[Omniauth::ATTEMPT]));
        $next = new Omniauth($kit->store, $kit->methods, new ArrayState($session), clock: $kit->clock);
        $result = $next->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret]);

        self::assertTrue($result->isAuthenticated());
        self::assertSame($kit->paul, $result->account);
        self::assertArrayNotHasKey(Omniauth::ATTEMPT, $session, 'cleared');
    }

    public function testAFirstFactorStartsANewAttempt(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $result = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine']);

        self::assertSame([Factor::Password], $result->passed);
        self::assertSame($kit->ringo, $result->account);
        self::assertSame(Failure::NoAttempt, $kit->omniauth->verify('email-code', Kit::context(), ['code' => '1'])->failure);
    }

    public function testAbandonForgetsTheAttempt(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->abandon();

        self::assertNull($kit->omniauth->attempt());
        self::assertSame(Failure::NoAttempt, $kit->omniauth->begin('email-code', Kit::context())->failure);
    }

    public function testAnAccountBannedBetweenTheTwoFactorsIsRefusedAtTheSecond(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        $kit->paul->refused = 'locked';
        $result = $kit->omniauth->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret]);

        self::assertSame(Failure::AccountRefused, $result->failure);
        self::assertSame('locked', $result->reason);
        self::assertNull($kit->omniauth->attempt());
    }

    public function testAnAccountGoneBetweenTheTwoFactorsEndsTheAttempt(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->session[Omniauth::ATTEMPT]['handle'] = '404';

        self::assertSame(Failure::NoAttempt, $kit->omniauth->verify('email-code', Kit::context(), ['code' => '1'])->failure);
    }

    public function testAListenerOfVerifyingRefusesBeforeTheMethodChecksAnything(): void
    {
        $kit = new Kit();
        $guard = new class implements EventDispatcherInterface {
            public array $seen = [];

            public function dispatch(object $event): object
            {
                $this->seen[] = $event;
                if ($event instanceof Verifying && 'ringo' === $event->identifier) {
                    $event->refuse('captcha');
                }
                if ($event instanceof Beginning) {
                    $event->refuse('not-human');
                }

                return $event;
            }
        };
        $omniauth = new Omniauth($kit->store, $kit->methods, new ArrayState(), events: $guard, clock: $kit->clock);
        $kit->ringo->password = 'not a hash: the method would fail on it, it is never reached';
        $refused = $omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine']);

        self::assertSame(Failure::Refused, $refused->failure);
        self::assertSame('captcha', $refused->reason);
        self::assertInstanceOf(SignInFailed::class, $guard->seen[1]);
        self::assertSame(Failure::Refused, $guard->seen[1]->failure);
        self::assertSame(Failure::Refused, $omniauth->begin('link', Kit::context(), ['identifier' => 'paul'])->failure);
        self::assertSame(0, $kit->sender->count(), 'nothing sent');
        self::assertTrue($omniauth->verify('passkey', Kit::context(), ['handle' => '1'])->isAuthenticated());
        self::assertInstanceOf(SignedIn::class, end($guard->seen));
    }

    public function testAProviderVouchingForSomeoneUnknownIsAnIdentityToSignUp(): void
    {
        $kit = new Kit();
        $kit->methods->add(new class implements Method {
            public function factor(): Factor
            {
                return Factor::OAuth;
            }

            public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
            {
                return Verdict::unknown(new PendingIdentity('google', '1077', 'john@example.org', true, 'John'));
            }
        });
        $result = $kit->omniauth->verify('oauth', Kit::context(), ['code' => 'x', 'state' => 'y']);

        self::assertSame(Status::IdentityUnknown, $result->status);
        self::assertSame(['google', '1077', 'john@example.org', true], [$result->identity->provider, $result->identity->subject, $result->identity->email, $result->identity->emailVerified]);
        self::assertNull($result->account);
    }

    public function testAFailureEventNamesTheFactorAndNeverTheSecret(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'secret-typed']);
        $failed = end($kit->events);

        self::assertInstanceOf(SignInFailed::class, $failed);
        self::assertSame([Factor::Password, Failure::BadCredentials, 'ringo', '172.18.0.1'], [$failed->factor, $failed->failure, $failed->identifier, $failed->context->ip]);
        self::assertStringNotContainsString('secret-typed', serialize($kit->events));
    }

    public function testAHostsMistakesAreExceptions(): void
    {
        $kit = new Kit();
        try {
            $kit->omniauth->verify('totp', Kit::context());
            self::fail('No TOTP method is mounted.');
        } catch (UnknownMethod $e) {
            self::assertStringContainsString('"totp"', $e->getMessage());
        }
        foreach ([fn () => $kit->omniauth->begin('password', Kit::context()), fn () => $kit->omniauth->enrol('link', $kit->ringo), fn () => $kit->omniauth->revoke('link', $kit->ringo)] as $mistake) {
            try {
                $mistake();
                self::fail('A LogicException was expected.');
            } catch (\LogicException $e) {
                self::assertNotInstanceOf(UnknownMethod::class, $e);
            }
        }
        $this->expectException(\ValueError::class);
        $kit->omniauth->verify('sms', Kit::context());
    }

    public function testWithoutThrottleNorEventsTheSameSequence(): void
    {
        $kit = new Kit(throttle: false);
        for ($i = 0; $i < 10; ++$i) {
            $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help']);
        }
        $bare = new Omniauth($kit->store, new Methods($kit->methods), new ArrayState());

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated());
        self::assertSame(Status::SecondFactorRequired, $bare->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be'])->status);
    }
}
