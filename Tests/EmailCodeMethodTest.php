<?php

namespace Omniauth\Tests;

use Omniauth\EmailCode\EmailCodeMethod;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Challenge;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use PHPUnit\Framework\TestCase;

/** QA5 (Marco, 2026-10-20): the code by e-mail is kept hashed. */
final class EmailCodeMethodTest extends TestCase
{
    public function testAPasswordThenTheCodeSentByEMail(): void
    {
        $kit = new Kit();
        $first = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be', 'remember' => true]);

        self::assertSame(Status::SecondFactorRequired, $first->status);
        self::assertSame($kit->paul, $first->account);
        self::assertSame([Factor::EmailCode], $first->offered);
        self::assertSame([Factor::EmailCode], $kit->omniauth->attempt()->offered(), 'what the second-factor page offers');

        $challenge = $kit->omniauth->begin('email-code', Kit::context());
        self::assertSame(Challenge::SENT, $challenge->kind);
        $code = $kit->sender->last()->secret;
        self::assertMatchesRegularExpression('~^[1-9]\d{5}$~', $code, 'six digits, as scheb/2fa draws them');
        self::assertSame('2026-10-20 12:10:00', $kit->sender->last()->until->format('Y-m-d H:i:s'));

        $result = $kit->omniauth->verify('email-code', Kit::context(), ['code' => $code]);
        self::assertSame(Status::Authenticated, $result->status);
        self::assertSame([Factor::Password, Factor::EmailCode], $result->passed);
        self::assertTrue($result->remember, 'ticked at the first factor');
        self::assertNull($kit->paul->emailCode, 'forgotten once used');
        self::assertSame(['Verifying', 'SecondFactorRequired', 'Beginning', 'Verifying', 'SignedIn'], $kit->eventNames());
    }

    public function testTheStoreKeepsAKeyedHashBoundToTheAccountNeverTheCode(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        $code = $kit->sender->last()->secret;

        self::assertNotSame($code, $kit->paul->emailCode);
        self::assertSame(hash_hmac('sha256', "2\0".$code, Kit::SECRET), $kit->paul->emailCode);
        self::assertNotSame($kit->emailCode->hash($kit->ringo, $code), $kit->paul->emailCode, 'the same code for another account is another hash');
    }

    public function testAWrongCodeThenTheRightOne(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());

        self::assertSame(Failure::BadCode, $kit->omniauth->verify('email-code', Kit::context(), ['code' => '000000'])->failure);
        self::assertTrue($kit->omniauth->verify('email-code', Kit::context(), ['code' => ' '.$kit->sender->last()->secret.' '])->isAuthenticated());
    }

    public function testACodeServesOnce(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        $code = $kit->sender->last()->secret;
        $kit->omniauth->verify('email-code', Kit::context(), ['code' => $code]);

        self::assertSame(Failure::NoAttempt, $kit->omniauth->verify('email-code', Kit::context(), ['code' => $code])->failure, 'the attempt is over');
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        self::assertSame(Failure::Expired, $kit->omniauth->verify('email-code', Kit::context(), ['code' => $code])->failure, 'and a new attempt has no code until one is sent');
    }

    public function testACodeServesUntilItsDeadline(): void
    {
        $kit = new Kit();
        $kit->methods->add(new EmailCodeMethod($kit->store, $kit->store->emailCodes(), $kit->sender, Kit::SECRET, lifetime: 120, clock: $kit->clock));
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        $code = $kit->sender->last()->secret;
        $kit->clock->advance(120);

        self::assertSame(Failure::Expired, $kit->omniauth->verify('email-code', Kit::context(), ['code' => $code])->failure);
        self::assertNull($kit->paul->emailCode, 'forgotten');
    }

    /** Ten minutes by default - as long as the attempt itself, which ends first since it began before the code. */
    public function testACodeOutlivesNoAttempt(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->clock->advance(60);
        $kit->omniauth->begin('email-code', Kit::context());
        $kit->clock->advance(540);

        self::assertSame('2026-10-20 12:11:00', $kit->sender->last()->until->format('Y-m-d H:i:s'));
        self::assertSame(Failure::Expired, $kit->omniauth->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret])->failure);
        self::assertNull($kit->omniauth->attempt());
    }

    public function testFiveWrongCodesPerAccountWhateverTheAddress(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(Failure::BadCode, $kit->omniauth->verify('email-code', Kit::context(ip: '10.0.0.'.$i), ['code' => '00000'.$i])->failure);
        }

        self::assertSame(Failure::Throttled, $kit->omniauth->verify('email-code', Kit::context(ip: '10.0.1.1'), ['code' => $kit->sender->last()->secret])->failure);
    }

    public function testNoCodeWithoutAFirstFactorNorOneTheAttemptDidNotOffer(): void
    {
        $kit = new Kit();

        self::assertSame(Failure::NoAttempt, $kit->omniauth->begin('email-code', Kit::context())->failure);
        self::assertSame(Failure::NoAttempt, $kit->omniauth->verify('email-code', Kit::context(), ['code' => '123456'])->failure);
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        self::assertSame(Failure::NotOffered, $kit->omniauth->verify('backup-code', Kit::context(), ['code' => 'A1B2C3-D4E5F6'])->failure, 'Paul has no backup codes');
        self::assertSame(0, $kit->sender->count());
    }

    public function testAnAttemptLastsTenMinutes(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->clock->advance(600);

        self::assertSame(Failure::Expired, $kit->omniauth->begin('email-code', Kit::context())->failure);
        self::assertNull($kit->omniauth->attempt());
    }

    public function testEnrolmentSendsACodeAndTurnsTheFactorOnOnceItComesBack(): void
    {
        $kit = new Kit();
        $enrolment = $kit->omniauth->enrol(Factor::EmailCode, $kit->ringo);

        self::assertSame(Challenge::SENT, $enrolment->begin(Kit::context())->kind);
        self::assertFalse($kit->ringo->emailCodeEnabled);
        self::assertSame(Failure::BadCode, $enrolment->confirm(Kit::context(), ['code' => '1'])->failure);
        self::assertTrue($enrolment->confirm(Kit::context(), ['code' => $kit->sender->last()->secret])->isDone());
        self::assertTrue($kit->ringo->emailCodeEnabled);
        self::assertSame(Status::SecondFactorRequired, $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->status);
    }

    public function testTurningItOffMakesTheTrustedDevicesFallBack(): void
    {
        $kit = new Kit();

        self::assertTrue($kit->omniauth->revoke('email-code', $kit->paul)->isDone());
        self::assertFalse($kit->paul->emailCodeEnabled);
        self::assertSame(1, $kit->paul->trustedVersion);
        self::assertSame(['Revoking', 'CredentialRemoved'], $kit->eventNames());
        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be'])->isAuthenticated());
    }

    public function testAListenerOfRevokingKeepsTheFactor(): void
    {
        $kit = new Kit();
        $refusing = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if ($event instanceof \Omniauth\Event\Revoking) {
                    $event->refuse('required-by-role');
                }

                return $event;
            }
        };
        $omniauth = new \Omniauth\Omniauth($kit->store, $kit->methods, new \Omniauth\ArrayState(), events: $refusing, clock: $kit->clock);
        $refused = $omniauth->revoke('email-code', $kit->paul);

        self::assertSame(Failure::Refused, $refused->failure);
        self::assertSame('required-by-role', $refused->reason);
        self::assertTrue($kit->paul->emailCodeEnabled);
    }
}
