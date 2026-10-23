<?php

namespace Omniauth\Tests;

use PHPUnit\Framework\TestCase;

/** The PHP blocks of docs/installation.md and docs/oauth.md, run as they are written (their autoloader the one of this run). */
final class DocsTest extends TestCase
{
    public function testTheInstallationPageRunsAsWritten(): void
    {
        [$block, $out] = self::runPage('installation.md');

        self::assertSame("second-factor-required: email-code\nauthenticated as ringo by password + email-code\ntrusted_device cookie to set\n", $out);
        foreach (['second-factor-required: email-code', 'authenticated as ringo by password + email-code'] as $line) {
            self::assertStringContainsString("\n// $line\n", $block, 'the page says what it prints');
        }
    }

    public function testTheIdentityProvidersPageRunsAsWritten(): void
    {
        [$block, $out] = self::runPage('oauth.md');

        $lines = ['authenticated as ringo, linked to google 108204', 'identity-unknown: john@example.org'];
        self::assertSame(implode("\n", $lines)."\n", $out);
        foreach ($lines as $line) {
            self::assertStringContainsString("\n// $line\n", $block, 'the page says what it prints');
        }
    }

    /** @return array{string, string} the page's first PHP block and what it printed */
    private static function runPage(string $page): array
    {
        $text = (string) file_get_contents(__DIR__.'/../docs/'.$page);
        self::assertSame(1, preg_match('~```php\n(.*?)```~s', $text, $block));
        $autoload = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $code = str_replace("require 'vendor/autoload.php';", 'require '.var_export($autoload, true).';', $block[1], $replaced);
        self::assertSame(1, $replaced);
        $script = tempnam(sys_get_temp_dir(), 'omniauth-docs');
        file_put_contents($script, $code);
        try {
            $process = proc_open([\PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $err.$out);
        } finally {
            unlink($script);
        }

        return [$block[1], $out];
    }
}
