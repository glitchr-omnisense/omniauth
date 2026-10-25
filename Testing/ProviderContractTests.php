<?php

namespace Omniauth\Testing;

use Omniauth\OAuth\Authorization;
use Omniauth\OAuth\Exception\ProviderError;
use Omniauth\OAuth\Identity;
use Omniauth\OAuth\Provider\Provider;
use Omniauth\OAuth\Provider\ProviderFactory;
use Omniauth\OAuth\Provider\Providers;

/**
 * What every identity provider does, as PHPUnit tests: a provider package's
 * test case uses this trait, gives the provider and a way to make its server
 * answer (recorded answers: no test reaches the network), and the suite
 * checks the Provider contract:
 *
 * - the authorization address carries the trip's state, its S256 challenge
 *   (never the verifier), the nonce, `response_type=code`, the client and the
 *   return address - the configured one, or its path on the trip's origin
 *   (none known: a LogicException) - and asks the server nothing;
 * - a code the server vouches for is exchanged for an Identity of this
 *   provider: its subject, an address said verified only when there is one;
 * - a code the server refuses, and a server that does not answer, are a
 *   ProviderError (reason "exchange-failed") - never another exception, and
 *   never with the client's secret in its message;
 * - made by its factory (when it has one) from a configuration entry: named
 *   after the entry's key, null for an entry of another kind; through
 *   Providers::configured(), an entry without a client_id is left out and one
 *   without a redirect_uri is a mistake.
 *
 *   final class GoogleContractTest extends TestCase
 *   {
 *       use ProviderContractTests;
 *
 *       protected function provider(string $redirectUri): Provider { ... a GoogleProvider on a Guzzle MockHandler ... }
 *       protected function consented(Provider $provider, Authorization $authorization): string { ... the token answer queued, with the nonce ...; return 'a-code'; }
 *       protected function refused(Provider $provider): string { ... an invalid_grant queued ...; return 'a-code'; }
 *       protected function unanswered(Provider $provider): string { ... a 503 queued ...; return 'a-code'; }
 *   }
 *
 * Requires phpunit/phpunit (the package's require-dev).
 */
trait ProviderContractTests
{
    /** The provider under test, its return address $redirectUri (an absolute address, or a path), its server's answers in the test's hands. */
    abstract protected function provider(string $redirectUri): Provider;

    /**
     * The person consented at $provider's authorization address for
     * $authorization: the server will vouch for them on the code returned
     * (its answers queued, carrying the authorization's nonce).
     */
    abstract protected function consented(Provider $provider, Authorization $authorization, string $authorizationUrl): string;

    /** The server will refuse the code returned (an invalid_grant). */
    abstract protected function refused(Provider $provider): string;

    /** The server will not answer the exchange of the code returned (an HTTP 503, or no connection); null for a provider in memory. */
    protected function unanswered(Provider $provider): ?string
    {
        return null;
    }

    /** The factory that makes this provider from a configuration entry; null when it has none. */
    protected function factory(): ?ProviderFactory
    {
        return null;
    }

    /** @return array<string, mixed> a configuration entry of the factory's kind (client_id, client_secret, redirect_uri, type...) */
    protected function entry(): array
    {
        return [];
    }

    /** The client's secret, which no ProviderError message may show; null when it has none. */
    protected function secret(): ?string
    {
        return null;
    }

    /** Whether the provider is an OpenID Connect one: its authorization address carries the nonce. */
    protected function sendsNonce(): bool
    {
        return true;
    }

    /** @return array<array-key, string> */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $query);
    }

    public function testTheAuthorizationAddressCarriesTheTrip(): void
    {
        $return = 'https://example.org/login/contract/callback';
        $provider = $this->provider($return);
        self::assertNotSame('', $provider->name(), 'a provider has its key');

        $authorization = Authorization::fresh();
        $url = $provider->authorizationUrl($authorization);
        self::assertMatchesRegularExpression('~^https://[^/?#]+[^?#]*\?~', $url, 'an https address with its query');
        $query = self::queryOf($url);

        self::assertSame($authorization->state, $query['state'] ?? null, 'the state');
        self::assertSame($authorization->challenge(), $query['code_challenge'] ?? null, 'the S256 challenge of the verifier');
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame($return, $query['redirect_uri'] ?? null, 'the configured return address');
        self::assertNotSame('', $query['client_id'] ?? '', 'the client');
        if ($this->sendsNonce()) {
            self::assertSame($authorization->nonce, $query['nonce'] ?? null, 'the nonce');
        }
        self::assertStringNotContainsString($authorization->verifier, $url, 'never the verifier');
        if (null !== $this->secret()) {
            self::assertStringNotContainsString($this->secret(), $url, 'never the secret');
        }

        $other = Authorization::fresh();
        self::assertNotSame($url, $provider->authorizationUrl($other), 'each trip its own address');
    }

    public function testAReturnPathIsCompletedByTheTripsOrigin(): void
    {
        $provider = $this->provider('/login/contract/callback');

        $query = self::queryOf($provider->authorizationUrl(Authorization::fresh('https://second.example:8443')));
        self::assertSame('https://second.example:8443/login/contract/callback', $query['redirect_uri'] ?? null);

        $this->expectException(\LogicException::class);
        $provider->authorizationUrl(Authorization::fresh());
    }

    public function testACodeTheServerVouchesForIsAnIdentityOfThisProvider(): void
    {
        $provider = $this->provider('https://example.org/login/contract/callback');
        $authorization = Authorization::fresh();
        $code = $this->consented($provider, $authorization, $provider->authorizationUrl($authorization));

        $identity = $provider->identify($code, $authorization);
        self::assertInstanceOf(Identity::class, $identity);
        self::assertSame($provider->name(), $identity->provider, 'the identity is this provider\'s');
        self::assertNotSame('', $identity->subject);
        if ($identity->emailVerified) {
            self::assertNotNull($identity->email, 'an address said verified is an address');
        }
        if (null !== $identity->email) {
            self::assertStringContainsString('@', $identity->email);
        }
        self::assertSame($identity->subject, $identity->pending()->subject);
    }

    public function testARefusedOrUnansweredExchangeIsAProviderError(): void
    {
        $provider = $this->provider('https://example.org/login/contract/callback');
        // Queued in this order, asked in this order.
        foreach (array_filter(['refused' => $this->refused($provider), 'unanswered' => $this->unanswered($provider)], static fn (?string $code): bool => null !== $code) as $case => $code) {
            $thrown = null;
            try {
                $provider->identify($code, Authorization::fresh());
            } catch (\Throwable $e) {
                $thrown = $e;
            }
            self::assertInstanceOf(ProviderError::class, $thrown, \sprintf('%s: %s', $case, null === $thrown ? 'no exception' : $thrown::class.' '.$thrown->getMessage()));
            self::assertSame(ProviderError::EXCHANGE, $thrown->reason, $case);
            if (null !== $this->secret()) {
                self::assertStringNotContainsString($this->secret(), $thrown->getMessage(), $case.': never the secret');
            }
        }
    }

    public function testItsFactoryMakesItFromAConfigurationEntry(): void
    {
        $factory = $this->factory();
        if (null === $factory) {
            $this->addToAssertionCount(1); // given as it is, never configured

            return;
        }
        $entry = $this->entry();

        $provider = $factory->create('contract', $entry);
        self::assertInstanceOf(Provider::class, $provider);
        self::assertSame('contract', $provider->name(), 'named after the entry\'s key');
        self::assertNull($factory->create('contract', ['type' => 'no-such-kind'] + $entry), 'an entry of another kind is not its');

        $configured = Providers::configured(['contract' => $entry, 'unconfigured' => array_diff_key($entry, ['client_id' => true])], $factory);
        self::assertSame(['contract'], $configured->names(), 'an entry without a client_id is left out');

        $this->expectException(\InvalidArgumentException::class);
        Providers::configured(['contract' => array_diff_key($entry, ['redirect_uri' => true])], $factory);
    }
}
