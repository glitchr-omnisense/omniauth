<?php

namespace Omniauth\Tests;

use Omniauth\OAuth\Authorization;
use Omniauth\OAuth\Provider\Provider;
use Omniauth\OAuth\Testing\FakeProvider;
use Omniauth\Testing\ProviderContractTests;
use PHPUnit\Framework\TestCase;

/** The fake provider, a host's stand-in for Google in its tests, keeps the contract it stands in for. */
final class FakeProviderContractTest extends TestCase
{
    use ProviderContractTests;

    protected function provider(string $redirectUri): Provider
    {
        return new FakeProvider('fake', $redirectUri);
    }

    protected function consented(Provider $provider, Authorization $authorization, string $authorizationUrl): string
    {
        \assert($provider instanceof FakeProvider);

        return $provider->consent($authorizationUrl, ['sub' => '110169484474386276334', 'email' => 'ringo@example.org', 'email_verified' => true, 'name' => 'Ringo Starr'])['code'];
    }

    protected function refused(Provider $provider): string
    {
        return 'a-code-it-never-gave';
    }
}
