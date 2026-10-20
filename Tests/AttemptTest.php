<?php

namespace Omniauth\Tests;

use Omniauth\Attempt;
use Omniauth\Factor;
use PHPUnit\Framework\TestCase;

final class AttemptTest extends TestCase
{
    public function testItRoundTripsThroughAnArray(): void
    {
        $attempt = new Attempt(1792497600, '2');
        $attempt->pass(Factor::Password);
        $attempt->pass(Factor::Password);
        $attempt->offer([Factor::EmailCode, Factor::BackupCode]);
        $attempt->keep(Factor::EmailCode, 1792498200);
        $attempt->keep('passkey', '{"challenge":"x"}');
        $attempt->remember(true);
        $back = Attempt::fromArray(json_decode(json_encode($attempt->toArray()), true));

        self::assertSame($attempt->toArray(), $back->toArray());
        self::assertSame([Factor::Password], $back->passed());
        self::assertSame(1792498200, $back->kept('email-code'));
        self::assertSame('{"challenge":"x"}', $back->take(Factor::Passkey));
        self::assertNull($back->take(Factor::Passkey), 'taken once');
        self::assertTrue($back->remembers());
        self::assertNull($back->account(), 'the conductor loads the account from the handle');
    }

    public function testARestartKeepsOnlyWhatCeremoniesKept(): void
    {
        $attempt = new Attempt(1, '2', [Factor::Password], [Factor::EmailCode], ['passkey' => 'challenge'], true);
        $restarted = $attempt->restart(5);

        self::assertSame(['started' => 5, 'handle' => null, 'passed' => [], 'offered' => [], 'kept' => ['passkey' => 'challenge'], 'remember' => false], $restarted->toArray());
    }

    public function testAnythingMalformedIsNoAttempt(): void
    {
        foreach ([null, 'x', [], ['started' => '1'], ['handle' => '2']] as $data) {
            self::assertNull(Attempt::fromArray($data));
        }
        self::assertSame([Factor::Password], Attempt::fromArray(['started' => 1, 'passed' => ['password', 'sms', 3]])->passed(), 'unknown factors dropped');
    }
}
