<?php

namespace Omniauth\Tests;

use Omniauth\Omniauth;
use PHPUnit\Framework\TestCase;

/**
 * omniscient/omniauth with no framework: in PHP processes of their own (this
 * one has loaded PHPUnit), the harness's bare script signs in by hand - a
 * password, a code by e-mail, a trusted browser, a backup code, a link, a
 * password hashed by Symfony past 72 bytes, the throttle - and every class
 * of the package is loaded. Nothing may come from outside the package but
 * the PSR interfaces it requires and symfony/password-hasher (a standalone
 * component): no class of a framework, an ORM, a template engine, scheb/2fa
 * or an application - even in the harness, where scheb/2fa and Symfony's
 * bundles are installed beside it. The sign-in through an identity provider
 * (Omniauth\OAuth\, formerly omniauth/oauth) by hand too, on the fake
 * provider: no OAuth library loaded - league/oauth2-client is the provider
 * packages' (omniauth/google), only suggested here.
 */
final class BareTest extends TestCase
{
    private const FOREIGN = '~^(?:Symfony\\\\(?!Component\\\\PasswordHasher\\\\|Polyfill\\\\)|Omniauth\\\\(?:WebAuthn|Otp|Passkey|Totp|Google)\\\\|(?:Illuminate|Laravel|Doctrine|Twig|Scheb|Lcobucci|OTPHP|Webauthn|League|GuzzleHttp|Omnibridge|Base|App)\\\\)~';
    private const ALLOWED_FILES = '~/vendor/(?:psr/(?:cache|clock|event-dispatcher|simple-cache)|symfony/password-hasher|composer)/|/vendor/autoload\.php$~';

    public function testASignInByHandLoadsNothingButOmniauthThePsrInterfacesAndThePasswordHasher(): void
    {
        $report = self::php([__DIR__.'/../docker/harness/bin/bare']);

        self::assertSame(['authenticated', 'ringo', ['password']], [$report['password']['status'], $report['password']['account'], $report['password']['passed']]);
        self::assertSame(['second-factor-required', ['email-code', 'backup-code']], [$report['two-steps'][0]['status'], $report['two-steps'][0]['offered']]);
        self::assertSame('sent', $report['two-steps'][1]);
        self::assertSame(['authenticated', ['password', 'email-code'], true], [$report['two-steps'][2]['status'], $report['two-steps'][2]['passed'], $report['two-steps'][2]['trustDevice']]);
        self::assertSame(['authenticated', ['password', 'trusted-device']], [$report['two-steps'][3]['status'], $report['two-steps'][3]['passed']], 'the trusted browser skips the code');
        self::assertMatchesRegularExpression('~^eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9\.[\w-]+\.[\w-]+$~', $report['cookie'], 'scheb/2fa\'s header');
        self::assertSame([['authenticated', ['password', 'backup-code']], 0], [[$report['backup-code'][0]['status'], $report['backup-code'][0]['passed']], $report['backup-code'][1]]);
        self::assertSame(['authenticated', true, 'failed', 'invalid-link'], [$report['link'][0]['status'], $report['link'][0]['remember'], $report['link'][1]['status'], $report['link'][1]['failure']]);
        self::assertSame(['authenticated', false], [$report['long-password'][0]['status'], $report['long-password'][1]], 'Symfony\'s prehash: password_verify() alone misses it');
        self::assertSame(['bad-credentials', 'bad-credentials', 'bad-credentials', 'bad-credentials', 'bad-credentials', 'throttled'], $report['throttle'], 'five a minute for an identifier from an address');

        self::assertContains(Omniauth::class, $report['loaded']['symbols']);
        self::assertSame([], self::foreign($report['loaded']['symbols']), 'no class of a framework, an ORM, Twig, scheb/2fa or an application');
        self::assertSame([], self::outside($report['loaded']['files'], $report['loaded']['eager']), 'no file but the package, the PSR interfaces and the password hasher');
    }

    public function testAnOAuthSignInByHandLoadsNoOAuthLibrary(): void
    {
        $report = self::php([__DIR__.'/OAuth/Fixtures/bare.php', self::autoload()]);

        self::assertSame(['authenticated', 'ringo', ['oauth']], [$report['linked']['status'], $report['linked']['account'], $report['linked']['passed']], 'a verified address links');
        self::assertSame(['google' => '108204'], $report['identities']);
        self::assertSame(['authenticated', 'ringo'], [$report['by-subject']['status'], $report['by-subject']['account']], 'then the subject alone');
        self::assertSame(['identity-unknown', null, ['9001', 'john@example.org', true]], [$report['unknown']['status'], $report['unknown']['account'], $report['unknown']['identity']], 'no account made');
        self::assertSame('identity-unknown', $report['unverified']['status'], 'an unverified address finds no account');
        self::assertSame(['failed', 'bad-credentials', 'state-mismatch'], [$report['forged']['status'], $report['forged']['failure'], $report['forged']['reason']]);
        self::assertSame(['failed', 'bad-credentials', 'exchange-failed'], [$report['injected']['status'], $report['injected']['failure'], $report['injected']['reason']], 'PKCE');
        self::assertSame(['failed', 'not-enrolled', 'link-from-settings'], [$report['by-address-with-a-second-factor']['status'], $report['by-address-with-a-second-factor']['failure'], $report['by-address-with-a-second-factor']['reason']], 'QA3');
        self::assertSame(['second-factor-required', ['oauth'], ['email-code']], [$report['second-factor']['status'], $report['second-factor']['passed'], $report['second-factor']['offered']], 'QA2');
        self::assertSame(['authenticated', ['oauth', 'email-code']], [$report['then-the-code']['status'], $report['then-the-code']['passed']]);

        self::assertContains('Omniauth\OAuth\OAuthMethod', $report['loaded']['symbols']);
        self::assertSame([], self::foreign($report['loaded']['symbols']), 'no OAuth library, no framework');
        self::assertSame([], self::outside($report['loaded']['files'], $report['loaded']['eager']));
    }

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $report = self::php([__DIR__.'/Fixtures/load-all.php', self::autoload(), \dirname(__DIR__)]);

        self::assertGreaterThan(70, \count($report['loaded']));
        self::assertContains('Omniauth\Testing\InMemoryStore', $report['loaded']);
        self::assertContains('Omniauth\TrustedDevice\TrustedDevices', $report['loaded']);
        foreach (['OAuthMethod', 'Provider\Providers', 'Provider\ProviderFactory', 'Provider\LeagueProvider', 'Testing\FakeProvider', 'NoSecondFactorLinking'] as $class) {
            self::assertContains('Omniauth\OAuth\\'.$class, $report['loaded'], 'loaded without league/oauth2-client');
        }
        self::assertSame([], self::foreign($report['symbols']));
        self::assertSame([], self::outside($report['files'], $report['eager']));
    }

    /** The check is not blind: a framework's class, once loaded, is seen. */
    public function testTheReportSeesWhatIsLoaded(): void
    {
        if (!class_exists(\Symfony\Component\Cache\Adapter\ArrayAdapter::class)) {
            self::markTestSkipped('symfony/cache is not installed.');
        }
        $report = self::php([__DIR__.'/Fixtures/load-all.php', self::autoload(), \dirname(__DIR__), \Symfony\Component\Cache\Adapter\ArrayAdapter::class]);

        self::assertContains(\Symfony\Component\Cache\Adapter\ArrayAdapter::class, self::foreign($report['symbols']));
        self::assertNotEmpty(preg_grep('~/symfony/cache/~', self::outside($report['files'], $report['eager'])));
        self::assertContains('Psr\Clock\ClockInterface', $report['symbols']);
    }

    /** @return list<string> */
    private static function foreign(array $symbols): array
    {
        return array_values(preg_grep(self::FOREIGN, $symbols));
    }

    /**
     * The files loaded from outside the package but those allowed - and those
     * Composer includes for every package at start (autoload "files":
     * polyfills, functions, and what they include beside them), which say
     * nothing of what omniauth uses. Installed alone, the package's vendor/
     * sits in its own directory: what is under it is outside all the same.
     *
     * @return list<string>
     */
    private static function outside(array $files, array $eager): array
    {
        $root = realpath(\dirname(__DIR__)).'/';
        $eager = array_map('dirname', array_filter($eager));

        return array_values(array_filter($files, static fn (?string $file) => null !== $file && (!str_starts_with($file, $root) || str_starts_with($file, $root.'vendor/')) && !\in_array(\dirname($file), $eager, true) && !preg_match(self::ALLOWED_FILES, $file)));
    }

    /** The autoloader that knows omniauth and its tests: not the one that loaded PHPUnit first, should they differ. */
    private static function autoload(): string
    {
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if ($loader->findFile(Omniauth::class) && $loader->findFile(Fixtures\Kit::class)) {
                return $vendor.'/autoload.php';
            }
        }
        self::markTestSkipped('No autoloader knows omniauth\'s tests.');
    }

    /** @return array<string, mixed> */
    private static function php(array $arguments): array
    {
        $process = proc_open([\PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OMNIAUTH_AUTOLOAD' => self::autoload()] + getenv());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        $status = proc_close($process);
        $report = json_decode($out, true);
        self::assertSame(0, $status, $err.$out);
        self::assertIsArray($report, $err.$out);

        return $report;
    }
}
