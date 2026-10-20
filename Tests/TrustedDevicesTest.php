<?php

namespace Omniauth\Tests;

use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use Omniauth\Testing\FixedClock;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\TestAccount;
use Omniauth\TrustedDevice\TrustedDevices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Trusted\JwtTokenEncoder;

/**
 * The trusted_device cookie in scheb/2fa's format: the tokens omniauth writes
 * are those scheb's JwtTokenEncoder writes for the same input, byte for
 * byte, and each reads the other's.
 */
final class TrustedDevicesTest extends TestCase
{
    /**
     * Written by scheb/2fa 8.6.1's JwtTokenEncoder (lcobucci/jwt 5.6.1) with
     * the key Kit::SECRET, for "ringo", firewall "main", version 3, issued at
     * the moment given and valid 30 days (its default lifetime).
     */
    private const SCHEB = [
        'whole seconds' => ['2026-10-20 12:00:00 UTC', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE3OTI0OTc2MDAsImV4cCI6MTc5NTA4OTYwMCwidXNyIjoicmluZ28iLCJmd2wiOiJtYWluIiwidnNuIjozfQ.oW9VislYZwYAUIVapxAUU-FjQ0ULcpjGVhVoqScv9xw'],
        'with microseconds' => ['2026-10-20 12:00:00.250000 UTC', 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE3OTI0OTc2MDAuMjUsImV4cCI6MTc5NTA4OTYwMC4yNSwidXNyIjoicmluZ28iLCJmd2wiOiJtYWluIiwidnNuIjozfQ.oDTNCbrvIy7k5zVzUzHVBDLlvnuh3Ynp5nDt10yRC0U'],
    ];

    public static function schebTokens(): iterable
    {
        return self::SCHEB;
    }

    #[DataProvider('schebTokens')]
    public function testOmniauthWritesTheTokenSchebRecorded(string $at, string $scheb): void
    {
        $now = new \DateTimeImmutable($at);

        self::assertSame($scheb, self::devices($now)->encode('ringo', 'main', 3, $now, $now->add(new \DateInterval('PT2592000S'))));
    }

    #[DataProvider('schebTokens')]
    public function testATokenSchebRecordedIsTrusted(string $at, string $scheb): void
    {
        $account = new TestAccount('1', 'ringo', trustedVersion: 3);
        $devices = self::devices((new \DateTimeImmutable($at))->modify('+29 days'), new InMemoryStore($account));

        self::assertSame(['iat', 'exp', 'usr', 'fwl', 'vsn'], array_keys($devices->decode($scheb)));
        self::assertTrue($devices->trusts($account, $scheb, 'main'));
        self::assertFalse($devices->trusts($account, $scheb, 'admin'), 'another firewall');
        $account->trustedVersion = 4;
        self::assertFalse($devices->trusts($account, $scheb, 'main'), 'another version');
    }

    #[DataProvider('schebTokens')]
    public function testATokenIsGoodUntilItsEndAndNotBeforeItsIssue(string $at, string $scheb): void
    {
        $issued = new \DateTimeImmutable($at);

        self::assertNotNull(self::devices($issued)->decode($scheb), 'iat <= now');
        self::assertNull(self::devices($issued->modify('-1 second'))->decode($scheb), 'not yet issued');
        self::assertNotNull(self::devices($issued->modify('+2592000 seconds')->modify('-1 second'))->decode($scheb));
        self::assertNull(self::devices($issued->modify('+2592000 seconds'))->decode($scheb), 'now >= exp: expired, as lcobucci/jwt says');
    }

    /** Live, when scheb/2fa is installed (the harness): the same input, the same bytes, both ways. */
    #[DataProvider('schebTokens')]
    public function testTheSameTokensAsSchebsEncoderBothWays(string $at): void
    {
        if (!class_exists(JwtTokenEncoder::class)) {
            self::markTestSkipped('scheb/2fa is not installed (the harness installs it).');
        }
        $now = new \DateTimeImmutable($at);
        $until = $now->add(new \DateInterval('PT2592000S'));
        $scheb = new JwtTokenEncoder(Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(Kit::SECRET)), new FrozenClock($now));
        $ours = self::devices($now);

        foreach ([['ringo', 'main', 0], ['paul@example.org', 'admin', 17], ['élise ünïcode/€', 'main', 2]] as [$user, $firewall, $version]) {
            $token = $scheb->generateToken($user, $firewall, $version, $until)->toString();
            self::assertSame($token, $ours->encode($user, $firewall, $version, $now, $until));

            $read = $scheb->decodeToken($ours->encode($user, $firewall, $version, $now, $until));
            self::assertNotNull($read, 'scheb reads omniauth\'s token');
            self::assertSame([$user, $firewall, $version], [$read->claims()->get('usr'), $read->claims()->get('fwl'), $read->claims()->get('vsn')]);
            self::assertSame($user, $ours->decode($token)['usr'] ?? null, 'omniauth reads scheb\'s');
        }
        $other = new JwtTokenEncoder(Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('x', 32))), new FrozenClock($now));
        self::assertNull($ours->decode($other->generateToken('ringo', 'main', 0, $until)->toString()), 'another key');
    }

    public function testTrustingABrowserAfterTheSecondFactorSkipsItNextTime(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());
        $result = $kit->omniauth->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret, 'trust' => '1']);

        self::assertNotNull($result->trustDevice);
        self::assertSame(['paul', 'main', 0], [($claims = $kit->devices->decode($result->trustDevice))['usr'], $claims['fwl'], $claims['vsn']]);
        self::assertSame(2592000, $claims['exp'] - $claims['iat'], '30 days');

        $kit->clock->advance(86400);
        $next = $kit->omniauth->verify('password', Kit::context($result->trustDevice), ['identifier' => 'paul', 'password' => 'let-it-be']);
        self::assertSame(Status::Authenticated, $next->status);
        self::assertSame([Factor::Password, Factor::TrustedDevice], $next->passed);
        self::assertNull($next->trustDevice);
        self::assertSame(1, $kit->sender->count(), 'no new code sent');
    }

    public function testNoCookieWithoutAskingNorAfterAFirstFactorAlone(): void
    {
        $kit = new Kit();
        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        $kit->omniauth->begin('email-code', Kit::context());

        self::assertNull($kit->omniauth->verify('email-code', Kit::context(), ['code' => $kit->sender->last()->secret])->trustDevice);
        self::assertNull($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine', 'trust' => true])->trustDevice, 'nothing to trust: no second factor passed');
    }

    public function testAnotherFirewallAnotherVersionOrAnExpiredTokenAsksForTheCodeAgain(): void
    {
        $kit = new Kit();
        $cookie = $kit->devices->remember($kit->paul, Kit::context());

        self::assertSame(Status::SecondFactorRequired, $kit->omniauth->verify('password', Kit::context($cookie, firewall: 'admin'), ['identifier' => 'paul', 'password' => 'let-it-be'])->status);
        $kit->clock->advance(2592000);
        self::assertSame(Status::SecondFactorRequired, $kit->omniauth->verify('password', Kit::context($cookie), ['identifier' => 'paul', 'password' => 'let-it-be'])->status, 'expired');
        $kit->clock->advance(-2592000);
        self::assertSame(Status::Authenticated, $kit->omniauth->verify('password', Kit::context($cookie), ['identifier' => 'paul', 'password' => 'let-it-be'])->status);
        self::assertTrue($kit->omniauth->revoke(Factor::TrustedDevice, $kit->paul)->isDone(), 'forget every browser');
        self::assertSame(Status::SecondFactorRequired, $kit->omniauth->verify('password', Kit::context($cookie), ['identifier' => 'paul', 'password' => 'let-it-be'])->status, 'another version');
    }

    public function testATamperedTokenIsNotTrusted(): void
    {
        $kit = new Kit();
        $token = $kit->devices->remember($kit->paul, Kit::context());
        [$head, $body, $signature] = explode('.', $token);
        $forged = $head.'.'.rtrim(strtr(base64_encode(str_replace('"vsn":0', '"vsn":0,"x":1', (string) base64_decode(strtr($body, '-_', '+/')))), '+/', '-_'), '=').'.'.$signature;
        $none = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=').'.'.$body.'.';

        self::assertNotNull($kit->devices->decode($token));
        foreach ([$forged, $none, $head.'.'.$body, 'garbage', ''] as $bad) {
            self::assertNull($kit->devices->decode($bad));
            self::assertFalse($kit->devices->trusts($kit->paul, $bad));
        }
    }

    /** As scheb's TrustedDeviceTokenStorage: other accounts' and firewalls' tokens kept, this one's replaced, the invalid dropped. */
    public function testTheCookieKeepsTheOtherTokens(): void
    {
        $kit = new Kit();
        $ringo = $kit->devices->remember($kit->ringo, Kit::context());
        $admin = $kit->devices->remember($kit->paul, Kit::context($ringo, firewall: 'admin'));
        $kit->clock->advance(10);
        $cookie = $kit->devices->remember($kit->paul, Kit::context($admin.';garbage;'.$kit->devices->remember($kit->paul, Kit::context())));
        $tokens = explode(';', $cookie);

        self::assertCount(3, $tokens);
        self::assertSame([['ringo', 'main'], ['paul', 'admin'], ['paul', 'main']], array_map(fn ($t) => [$kit->devices->decode($t)['usr'], $kit->devices->decode($t)['fwl']], $tokens));
        self::assertTrue($kit->devices->trusts($kit->ringo, $cookie));
        self::assertTrue($kit->devices->trusts($kit->paul, $cookie, 'admin'));
    }

    public function testTheKeyHas32BytesAtLeastAsLcobucciRequires(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedDevices(new InMemoryStore(), new InMemoryStore(), str_repeat('x', 31));
    }

    public function testWithoutAFirstFactorTheMethodRefuses(): void
    {
        $kit = new Kit();

        self::assertSame(Failure::NoAttempt, $kit->omniauth->verify('trusted-device', Kit::context('x'))->failure);
    }

    private static function devices(\DateTimeImmutable $now, ?InMemoryStore $store = null): TrustedDevices
    {
        $store ??= new InMemoryStore();

        return new TrustedDevices($store, $store, Kit::SECRET, clock: new FixedClock($now));
    }
}
