<?php

namespace Omniauth\Tests;

use Omniauth\Context;
use Omniauth\EnrolledSecondFactors;
use Omniauth\Factor;
use Omniauth\Method\Methods;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\StubSecondFactor;
use Omniauth\Testing\TestAccount;
use PHPUnit\Framework\TestCase;

/**
 * The second-factor rule, and Marco's decisions of 2026-10-20 as the default
 * policy expresses them: QA1, no second factor after a passkey; QA2, a second
 * factor after an identity provider.
 */
final class SecondFactorPolicyTest extends TestCase
{
    public function testTheDefaultFollowsAPasswordALinkAndAnIdentityProviderNotAPasskey(): void
    {
        self::assertSame([Factor::Password, Factor::Link, Factor::OAuth], EnrolledSecondFactors::AFTER);
        self::assertNotContains(Factor::Passkey, EnrolledSecondFactors::AFTER);
    }

    /** QA1: a passkey proves the device and the gesture that unlocks it. */
    public function testAPasskeySignsInWithNoSecondFactorEvenWhenOneIsSetUp(): void
    {
        $kit = new Kit();
        $result = $kit->omniauth->verify('passkey', Kit::context(), ['handle' => '2']);

        self::assertSame(Status::Authenticated, $result->status);
        self::assertSame($kit->paul, $result->account);
        self::assertSame([Factor::Passkey], $result->passed);
        self::assertTrue($kit->paul->emailCodeEnabled, 'Paul has a second factor, not asked');
    }

    /** QA2: the one difference with the bundles wanted by default - Google no longer skips the second factor. */
    public function testAnIdentityProviderAsksForTheSecondFactor(): void
    {
        $kit = new Kit();
        $result = $kit->omniauth->verify('oauth', Kit::context(), ['handle' => '2']);

        self::assertSame(Status::SecondFactorRequired, $result->status);
        self::assertSame([Factor::EmailCode], $result->offered);
        $kit->omniauth->begin('email-code', Kit::context());
        self::assertSame([Factor::OAuth, Factor::EmailCode], $kit->omniauth->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret])->passed);
    }

    public function testAnAccountWithoutSecondFactorSignsInByAnyFirstFactor(): void
    {
        $kit = new Kit();

        foreach (['passkey', 'oauth'] as $factor) {
            self::assertTrue($kit->omniauth->verify($factor, Kit::context(), ['handle' => '1'])->isAuthenticated(), $factor);
        }
    }

    public function testTheListOfFirstFactorsIsAHostsSetting(): void
    {
        $kit = new Kit();
        $strict = new Kit(policy: new EnrolledSecondFactors($kit->methods, [Factor::Password, Factor::Link, Factor::OAuth, Factor::Passkey]));

        self::assertSame(Status::SecondFactorRequired, $strict->omniauth->verify('passkey', Kit::context(), ['handle' => '2'])->status, 'a host may ask it after a passkey too');
    }

    public function testEverySecondFactorSetUpIsOfferedBackupCodesLast(): void
    {
        $store = new InMemoryStore($account = new TestAccount('1', 'ringo'));
        $account->backupCodes = ['$2y$04$x'];
        $methods = new Methods([new \Omniauth\BackupCode\BackupCodeMethod($store), new StubSecondFactor(Factor::Totp, $store, ['1']), new \Omniauth\EmailCode\EmailCodeMethod($store, $store->emailCodes(), new \Omniauth\Testing\RecordingSender(), Kit::SECRET)]);
        $policy = new EnrolledSecondFactors($methods);
        $context = new Context();

        self::assertSame([Factor::Totp, Factor::BackupCode], $policy->required($account, [Factor::Password], $context));
        $account->emailCodeEnabled = true;
        self::assertSame([Factor::Totp, Factor::EmailCode, Factor::BackupCode], $policy->required($account, [Factor::Password], $context));
        self::assertSame([], $policy->required($account, [Factor::Password, Factor::Totp], $context), 'one passed is enough');
        self::assertSame([], $policy->required($account, [Factor::Password, Factor::TrustedDevice], $context), 'a trusted device is one');
        self::assertSame([], $policy->required($account, [Factor::Passkey], $context));
    }

    public function testAnAuthenticatorApplicationStoodInPassesAsTheSecondFactor(): void
    {
        $kit = new Kit();
        $kit->methods->add(new StubSecondFactor(Factor::Totp, $kit->store, ['1']));
        $first = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine']);

        self::assertSame([Factor::Totp], $first->offered);
        self::assertSame(\Omniauth\Failure::BadCode, $kit->omniauth->verify('totp', Kit::context(), ['code' => '000000'])->failure);
        self::assertTrue($kit->omniauth->verify('totp', Kit::context(), ['code' => '123456'])->isAuthenticated());
    }
}
