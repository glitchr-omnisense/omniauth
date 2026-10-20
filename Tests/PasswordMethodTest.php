<?php

namespace Omniauth\Tests;

use Omniauth\Failure;
use Omniauth\Password\PasswordMethod;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use Omniauth\Testing\TestAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final class PasswordMethodTest extends TestCase
{
    /** Hashed by symfony/password-hasher 8.1, algorithm "auto" (what security.yaml declares): bcrypt, cost 13. */
    private const SYMFONY_HASH = '$2y$13$jrKcKjaC/35WOH4z5X9DwuiTHp6UrHYpwkpBRMILTDzJMWU9KK39.';
    /** The same hasher, for a password of 102 bytes: prehashed in SHA-512 then base64 before bcrypt. */
    private const SYMFONY_LONG_HASH = '$2y$13$BVWUmb48bU9FJYPdeNyj8.UHRnUjw4slMumZ8pHtwRPu.e1cEBBz.';

    public function testTheRightPasswordSignsInAnAccountWithNoSecondFactor(): void
    {
        $kit = new Kit();
        $result = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo@example.org', 'password' => 'yellow-submarine', 'remember' => '1']);

        self::assertSame(Status::Authenticated, $result->status);
        self::assertSame($kit->ringo, $result->account);
        self::assertSame(['password'], array_column($result->passed, 'value'));
        self::assertTrue($result->remember, 'the host sets its remember-me');
        self::assertNull($result->trustDevice);
        self::assertSame(['Verifying', 'SignedIn'], $kit->eventNames());
        self::assertNull($kit->omniauth->attempt(), 'the attempt is cleared');
    }

    public function testTheUserNameFindsTheAccountAsWellAsTheAddress(): void
    {
        $kit = new Kit();

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'RINGO', 'password' => 'yellow-submarine'])->isAuthenticated());
    }

    public function testAWrongPasswordAndAnUnknownAddressGetTheSameAnswer(): void
    {
        $kit = new Kit();
        $wrong = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help']);
        $unknown = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'john@example.org', 'password' => 'help']);
        $empty = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo']);

        foreach ([$wrong, $unknown, $empty] as $result) {
            self::assertSame(Status::Failed, $result->status);
            self::assertSame(Failure::BadCredentials, $result->failure);
            self::assertNull($result->account);
        }
        self::assertSame(['Verifying', 'SignInFailed', 'Verifying', 'SignInFailed', 'Verifying', 'SignInFailed'], $kit->eventNames());
    }

    public function testABannedAccountIsRefusedWithItsReasonOnlyOnceThePasswordIsRight(): void
    {
        $kit = new Kit();
        $wrong = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'pete', 'password' => 'help']);
        $right = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'pete', 'password' => 'love-me-do']);

        self::assertSame(Failure::BadCredentials, $wrong->failure, 'nothing said of the account to whoever does not know its password');
        self::assertSame(Failure::AccountRefused, $right->failure);
        self::assertSame('banned', $right->reason);
        self::assertSame($kit->pete, $right->account);
        self::assertContains('AccountRefused', $kit->eventNames());
    }

    public function testFiveTriesAMinuteForAnIdentifierFromAnAddress(): void
    {
        $kit = new Kit();
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(Failure::BadCredentials, $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help'])->failure);
        }
        $throttled = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine']);

        self::assertSame(Failure::Throttled, $throttled->failure, 'even the right password: nothing is checked');
        self::assertTrue($kit->omniauth->verify('password', Kit::context(ip: '10.0.0.9'), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated(), 'from another address');
        $kit->clock->advance(60);
        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated(), 'a minute later');
    }

    public function testASuccessForgetsTheTries(): void
    {
        $kit = new Kit();
        for ($i = 0; $i < 4; ++$i) {
            $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help']);
        }
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine']);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(Failure::BadCredentials, $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help'])->failure);
        }
    }

    public function testAHashOfAnotherCostIsRehashedAtSignIn(): void
    {
        $kit = new Kit();
        $kit->ringo->password = (new NativePasswordHasher(cost: 5))->hash('yellow-submarine');

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated());
        self::assertStringStartsWith('$2y$04$', $kit->ringo->password, 'rehashed at the hasher\'s cost');
        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated());
    }

    /** A hash Symfony wrote (bcrypt 13, "auto") signs in through the default hasher, and is left as it is. */
    public function testAHashWrittenBySymfonySignsIn(): void
    {
        $kit = $this->withDefaultHasher($account = new TestAccount('9', 'john', 'john@example.org', self::SYMFONY_HASH));

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'john', 'password' => 'yellow-submarine'])->isAuthenticated());
        self::assertSame(self::SYMFONY_HASH, $account->password, 'cost 13 is the default hasher\'s: no rehash');
    }

    /**
     * Over 72 bytes Symfony prehashes the password: password_verify() alone
     * (Laravel's Hash) refuses it, omniauth signs it in.
     */
    public function testAPasswordLongerThan72BytesHashedBySymfonySignsIn(): void
    {
        $long = str_repeat('Fujisan at dawn, ', 6);
        $kit = $this->withDefaultHasher(new TestAccount('9', 'john', 'john@example.org', self::SYMFONY_LONG_HASH));

        self::assertSame(102, \strlen($long));
        self::assertFalse(password_verify($long, self::SYMFONY_LONG_HASH), 'password_verify() alone misses it');
        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'john', 'password' => $long])->isAuthenticated());
        self::assertSame(Failure::BadCredentials, $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'john', 'password' => substr($long, 0, 72)])->failure, 'its first 72 bytes are not it');
    }

    /** And the hasher security.yaml builds ("auto": a MigratingPasswordHasher) passed in, hashing now. */
    public function testTheHasherSecurityYamlBuildsHashesAndVerifiesTheSame(): void
    {
        $hasher = (new PasswordHasherFactory(['common' => ['algorithm' => 'auto', 'cost' => 4]]))->getPasswordHasher('common');
        $long = str_repeat('é', 50);
        $account = new TestAccount('9', 'john', 'john@example.org', $hasher->hash($long));
        $kit = $this->withDefaultHasher($account, $hasher);

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'john', 'password' => $long])->isAuthenticated());
    }

    public function testAnEnrolmentChangesThePasswordAndTheTrustedDevicesFallBack(): void
    {
        $kit = new Kit();
        $enrolment = $kit->omniauth->enrol('password', $kit->ringo);

        self::assertSame(Failure::BadCredentials, $enrolment->confirm(Kit::context(), ['password' => 'help', 'current' => 'wrong'])->failure, 'the current password, when given, is checked');
        self::assertSame(0, $kit->ringo->trustedVersion);
        self::assertTrue($enrolment->confirm(Kit::context(), ['password' => 'help', 'current' => 'yellow-submarine'])->isDone());
        self::assertSame(1, $kit->ringo->trustedVersion, 'a new password: the browsers trusted so far fall back');
        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'help'])->isAuthenticated());
        self::assertContains('CredentialAdded', $kit->eventNames());
    }

    /** The kit with the password method on the hasher given - by default, the method's own (bcrypt 13, Symfony's "auto"). */
    private function withDefaultHasher(TestAccount $account, ?PasswordHasherInterface $hasher = null): Kit
    {
        $kit = new Kit();
        $kit->store->add($account);
        $kit->methods->add(new PasswordMethod($kit->store, $kit->store, $hasher));

        return $kit;
    }
}
