<?php

namespace Omniauth\Tests;

use Omniauth\CacheThrottle;
use Omniauth\Testing\ArrayCache;
use Omniauth\Testing\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class CacheThrottleTest extends TestCase
{
    public static function caches(): iterable
    {
        yield 'PSR-16, Testing\ArrayCache' => ['psr16-array'];
        yield 'PSR-6, symfony/cache' => ['psr6'];
        yield 'PSR-16, symfony/cache' => ['psr16'];
    }

    #[DataProvider('caches')]
    public function testAWindowPerScopeAndKey(string $kind): void
    {
        [$throttle, $clock] = self::throttle($kind);

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($throttle->consume('password', 'ringo|1.2.3.4'));
        }
        self::assertFalse($throttle->consume('password', 'ringo|1.2.3.4'));
        self::assertTrue($throttle->consume('password', 'ringo|5.6.7.8'), 'another key');
        self::assertTrue($throttle->consume('email-code', 'ringo|1.2.3.4'), 'another scope');
        $clock->advance(59);
        self::assertFalse($throttle->consume('password', 'ringo|1.2.3.4'));
        $clock->advance(1);
        self::assertTrue($throttle->consume('password', 'ringo|1.2.3.4'), 'a new window');
    }

    #[DataProvider('caches')]
    public function testResetForgetsTheTries(string $kind): void
    {
        [$throttle] = self::throttle($kind);
        for ($i = 0; $i < 3; ++$i) {
            $throttle->consume('link.begin', 'account:{1}@/()');
        }
        self::assertFalse($throttle->consume('link.begin', 'account:{1}@/()'), 'three an hour; reserved characters in a key are fine');
        $throttle->reset('link.begin', 'account:{1}@/()');
        self::assertTrue($throttle->consume('link.begin', 'account:{1}@/()'));
    }

    public function testLimitsAreReplacedScopeByScope(): void
    {
        $throttle = new CacheThrottle(new ArrayCache(), ['password' => [1, 60]]);

        self::assertTrue($throttle->consume('password', 'k'));
        self::assertFalse($throttle->consume('password', 'k'));
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($throttle->consume('anything', 'k'), 'the "*" default');
        }
        self::assertFalse($throttle->consume('anything', 'k'));
    }

    /** @return array{CacheThrottle, FixedClock} */
    private static function throttle(string $kind): array
    {
        $clock = new FixedClock();
        if ('psr16-array' !== $kind && !class_exists(ArrayAdapter::class)) {
            self::markTestSkipped('symfony/cache is not installed.');
        }
        // symfony/cache's ArrayAdapter reads its own clock (microtime): the windows end on CacheThrottle's, which is what counts.
        $cache = match ($kind) {
            'psr16-array' => new ArrayCache($clock),
            'psr6' => new ArrayAdapter(),
            'psr16' => new Psr16Cache(new ArrayAdapter()),
        };

        return [new CacheThrottle($cache, clock: $clock), $clock];
    }
}
