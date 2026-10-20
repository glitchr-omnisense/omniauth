<?php

namespace Omniauth\Tests\Fixtures;

use Omniauth\ArrayState;
use Omniauth\BackupCode\BackupCodeMethod;
use Omniauth\CacheThrottle;
use Omniauth\Context;
use Omniauth\Contract\SecondFactorPolicy;
use Omniauth\EmailCode\EmailCodeMethod;
use Omniauth\Factor;
use Omniauth\Link\LinkMethod;
use Omniauth\Method\Method;
use Omniauth\Method\Methods;
use Omniauth\Omniauth;
use Omniauth\Password\PasswordMethod;
use Omniauth\Testing\ArrayCache;
use Omniauth\Testing\FixedClock;
use Omniauth\Testing\InMemoryStore;
use Omniauth\Testing\RecordingSender;
use Omniauth\Testing\StubMethod;
use Omniauth\Testing\TestAccount;
use Omniauth\TrustedDevice\TrustedDevices;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * The whole family mounted on the in-memory stores, as a host would: three
 * accounts (a password alone; a password and the code by e-mail; a banned
 * one), the five methods of the core, a passkey and an identity provider
 * stood in by StubMethod, a fixed clock, a recording sender, a throttle on an
 * array cache and every event recorded.
 */
final class Kit implements EventDispatcherInterface
{
    public const SECRET = 'a-kernel-secret-of-thirty-two-bytes-or-more';

    public readonly InMemoryStore $store;
    public readonly FixedClock $clock;
    public readonly RecordingSender $sender;
    public readonly NativePasswordHasher $hasher;
    public readonly Methods $methods;
    public readonly TrustedDevices $devices;
    public readonly EmailCodeMethod $emailCode;
    public readonly TestAccount $ringo;
    public readonly TestAccount $paul;
    public readonly TestAccount $pete;
    /** @var array<string, mixed> */
    public array $session = [];
    /** @var list<object> */
    public array $events = [];
    public Omniauth $omniauth;

    /** @param list<Method> $more */
    public function __construct(array $more = [], ?SecondFactorPolicy $policy = null, bool $throttle = true)
    {
        $this->clock = new FixedClock('2026-10-20 12:00:00 UTC');
        $this->sender = new RecordingSender();
        $this->hasher = new NativePasswordHasher(cost: 4);
        $this->store = new InMemoryStore(
            $this->ringo = new TestAccount('1', 'ringo', 'ringo@example.org', $this->hasher->hash('yellow-submarine'), 'Ringo'),
            $this->paul = new TestAccount('2', 'paul', 'paul@example.org', $this->hasher->hash('let-it-be'), 'Paul', emailCodeEnabled: true),
            $this->pete = new TestAccount('3', 'pete', 'pete@example.org', $this->hasher->hash('love-me-do'), 'Pete', refused: 'banned'),
        );
        $this->methods = new Methods([
            new PasswordMethod($this->store, $this->store, $this->hasher),
            new LinkMethod($this->store, $this->store, $this->sender, clock: $this->clock),
            $this->emailCode = new EmailCodeMethod($this->store, $this->store->emailCodes(), $this->sender, self::SECRET, clock: $this->clock),
            new BackupCodeMethod($this->store),
            $this->devices = new TrustedDevices($this->store, $this->store, self::SECRET, clock: $this->clock),
            new StubMethod(Factor::Passkey, $this->store),
            new StubMethod(Factor::OAuth, $this->store),
            ...$more,
        ]);
        $this->omniauth = new Omniauth(
            $this->store,
            $this->methods,
            new ArrayState($this->session),
            $policy,
            $throttle ? new CacheThrottle(new ArrayCache($this->clock), clock: $this->clock) : null,
            $this,
            $this->clock,
        );
    }

    public static function context(?string $cookie = null, string $ip = '172.18.0.1', string $firewall = 'main'): Context
    {
        return new Context('https://example.localhost', 'example.localhost', $ip, 'PHPUnit', $cookie, $firewall);
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /** @return list<string> the short names of the events dispatched, in their order */
    public function eventNames(): array
    {
        return array_map(static fn (object $e) => substr(strrchr($e::class, '\\') ?: $e::class, 1), $this->events);
    }
}
