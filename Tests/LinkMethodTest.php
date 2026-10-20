<?php

namespace Omniauth\Tests;

use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Link\LinkMethod;
use Omniauth\Method\Challenge;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use PHPUnit\Framework\TestCase;

/** QA4 (Marco, 2026-10-20): 15 minutes, once, hashed; a new link at each request, 3 an hour. */
final class LinkMethodTest extends TestCase
{
    public function testALinkIsSentAndSignsInOnceWithTheRememberMe(): void
    {
        $kit = new Kit();
        $challenge = $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo@example.org']);

        self::assertSame(Challenge::SENT, $challenge->kind);
        $message = $kit->sender->last();
        self::assertSame(Factor::Link, $message->factor);
        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]{43}$~', $message->secret, '32 random bytes, base64url');
        self::assertSame('2026-10-20 12:15:00', $message->until->format('Y-m-d H:i:s'), '15 minutes');

        $result = $kit->omniauth->verify('link', Kit::context(), ['token' => $message->secret]);
        self::assertSame(Status::Authenticated, $result->status);
        self::assertSame($kit->ringo, $result->account);
        self::assertTrue($result->remember, 'a link from the mailbox sets the remember-me, as omnibase\'s did');

        $again = $kit->omniauth->verify('link', Kit::context(), ['token' => $message->secret]);
        self::assertSame(Failure::InvalidLink, $again->failure, 'used once, gone');
    }

    public function testTheStoreKeepsTheHashNeverTheValue(): void
    {
        $kit = new Kit();
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo']);
        $value = $kit->sender->last()->secret;

        self::assertSame([LinkMethod::hash($value)], array_keys($kit->ringo->links));
        self::assertSame(hash('sha256', $value), LinkMethod::hash($value));
        self::assertNotContains($value, array_keys($kit->ringo->links));
    }

    public function testAnUnknownAddressGetsTheSameAnswerAndNothingIsSent(): void
    {
        $kit = new Kit();
        $challenge = $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'john@example.org']);

        self::assertSame(Challenge::SENT, $challenge->kind);
        self::assertSame(0, $kit->sender->count());
    }

    public function testEachRequestSendsANewLinkAndEachServes(): void
    {
        $kit = new Kit();
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo']);
        $first = $kit->sender->last()->secret;
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo']);
        $second = $kit->sender->last()->secret;

        self::assertNotSame($first, $second, 'no "a link is still valid, nothing sent" any more');
        self::assertTrue($kit->omniauth->verify('link', Kit::context(), ['token' => $second])->isAuthenticated());
        self::assertTrue($kit->omniauth->verify('link', Kit::context(), ['token' => $first])->isAuthenticated());
    }

    public function testALinkServesFifteenMinutes(): void
    {
        $kit = new Kit();
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo']);
        $kit->clock->advance(900);

        self::assertSame(Failure::InvalidLink, $kit->omniauth->verify('link', Kit::context(), ['token' => $kit->sender->last()->secret])->failure);
    }

    public function testThreeRequestsAnHour(): void
    {
        $kit = new Kit();
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Challenge::SENT, $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo'])->kind);
        }
        $fourth = $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo']);

        self::assertSame(Failure::Throttled, $fourth->failure);
        self::assertSame(3, $kit->sender->count());
        $kit->clock->advance(3600);
        self::assertSame(Challenge::SENT, $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'ringo'])->kind);
    }

    public function testAnUnknownOrEmptyTokenIsRefused(): void
    {
        $kit = new Kit();

        self::assertSame(Failure::InvalidLink, $kit->omniauth->verify('link', Kit::context(), ['token' => 'forged'])->failure);
        self::assertSame(Failure::InvalidLink, $kit->omniauth->verify('link', Kit::context())->failure);
    }

    /** The links issued before the switch, in clear: read as they are until their end, once. */
    public function testALinkKeptInClearBeforeTheSwitchServesOnceUntilItsEnd(): void
    {
        $kit = new Kit();
        $kit->ringo->plainLinks['legacy-value'] = $kit->clock->now()->getTimestamp() + 30 * 86400;
        $kit->paul->plainLinks['old-value'] = $kit->clock->now()->getTimestamp() - 1;

        self::assertTrue($kit->omniauth->verify('link', Kit::context(), ['token' => 'legacy-value'])->isAuthenticated());
        self::assertSame(Failure::InvalidLink, $kit->omniauth->verify('link', Kit::context(), ['token' => 'legacy-value'])->failure);
        self::assertSame(Failure::InvalidLink, $kit->omniauth->verify('link', Kit::context(), ['token' => 'old-value'])->failure);
    }

    public function testALinkAsksForTheSecondFactorAsAPasswordDoes(): void
    {
        $kit = new Kit();
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'paul']);
        $result = $kit->omniauth->verify('link', Kit::context(), ['token' => $kit->sender->last()->secret]);

        self::assertSame(Status::SecondFactorRequired, $result->status);
        self::assertSame([Factor::EmailCode], $result->offered);
    }

    public function testABannedAccountGetsALinkButCannotSignInWithIt(): void
    {
        $kit = new Kit();
        $kit->omniauth->begin('link', Kit::context(), ['identifier' => 'pete']);
        $result = $kit->omniauth->verify('link', Kit::context(), ['token' => $kit->sender->last()->secret]);

        self::assertSame(Failure::AccountRefused, $result->failure);
        self::assertSame('banned', $result->reason);
    }
}
