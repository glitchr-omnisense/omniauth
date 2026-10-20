<?php

namespace Omniauth\TrustedDevice;

use Omniauth\Attempt;
use Omniauth\Context;
use Omniauth\Contract\Accounts;
use Omniauth\Contract\TrustedVersion;
use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Method\Ambient;
use Omniauth\Method\Revocable;
use Omniauth\Method\Verdict;
use Omniauth\Proof;
use Omniauth\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * A second factor already passed on this browser: the trusted_device cookie,
 * written in scheb/2fa's very format so the devices trusted before the switch
 * stay trusted - JWTs signed HS256 by the host's secret (Symfony's
 * kernel.secret), claims iat, exp, "usr" (the account's identifier), "fwl"
 * (the firewall), "vsn" (the account's trusted version), several tokens
 * joined by ";". Encoded with PHP's own HMAC, byte for byte what
 * lcobucci/jwt writes for scheb's JwtTokenEncoder (dates with their
 * microseconds), and read as it reads them (signature, iat <= now < exp).
 *
 * A token counts while its version is the account's: TrustedVersion::revokeAll()
 * (a second factor turned off, a password changed) makes every device fall back.
 */
final class TrustedDevices implements Ambient, Revocable
{
    public const COOKIE = 'trusted_device';
    public const LIFETIME = 2592000;

    private const HEADER = ['typ' => 'JWT', 'alg' => 'HS256'];

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly TrustedVersion $versions,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $lifetime = self::LIFETIME,
        ?ClockInterface $clock = null,
    ) {
        // lcobucci/jwt refuses an HS256 key under 256 bits: scheb's tokens never had a shorter one.
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('The trusted devices\' key (the kernel\'s secret) needs 32 bytes at least, as HS256 does in lcobucci/jwt.');
        }
        $this->clock = $clock ?? new SystemClock();
    }

    public function factor(): Factor
    {
        return Factor::TrustedDevice;
    }

    public function verify(Attempt $attempt, Proof $proof, Context $context): Verdict
    {
        $account = $attempt->account();
        if (null === $account) {
            return Verdict::refused(Failure::NoAttempt);
        }

        return $this->trusts($account, (string) $context->trustedDevice, $context->firewall) ? Verdict::passed($account) : Verdict::refused(Failure::NotEnrolled);
    }

    /** Whether the cookie holds a valid token of this account, this firewall and the account's version. */
    public function trusts(object $account, string $cookie, string $firewall = 'main'): bool
    {
        $username = $this->accounts->identifier($account);
        $version = $this->versions->version($account);
        foreach ($this->tokens($cookie) as $claims) {
            if (($claims['usr'] ?? null) === $username && ($claims['fwl'] ?? null) === $firewall && ($claims['vsn'] ?? null) === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cookie's new value with this browser trusted for the account: as
     * scheb's TrustedDeviceTokenStorage writes it - the invalid and expired
     * tokens dropped, those of the account and firewall replaced by a new one,
     * the others kept.
     */
    public function remember(object $account, Context $context): string
    {
        $username = $this->accounts->identifier($account);
        $kept = [];
        foreach ($this->tokens((string) $context->trustedDevice) as $token => $claims) {
            if (($claims['usr'] ?? null) !== $username || ($claims['fwl'] ?? null) !== $context->firewall) {
                $kept[] = $token;
            }
        }
        $now = $this->clock->now();
        $kept[] = $this->encode($username, $context->firewall, $this->versions->version($account), $now, $now->add(new \DateInterval('PT'.$this->lifetime.'S')));

        return implode(';', $kept);
    }

    public function revoke(object $account): void
    {
        $this->versions->revokeAll($account);
    }

    /** One token, as scheb's JwtTokenEncoder::generateToken() makes it with lcobucci/jwt. */
    public function encode(string $username, string $firewall, int $version, \DateTimeImmutable $issuedAt, \DateTimeImmutable $until): string
    {
        $head = self::base64(self::json(self::HEADER));
        $body = self::base64(self::json(['iat' => self::date($issuedAt), 'exp' => self::date($until), 'usr' => $username, 'fwl' => $firewall, 'vsn' => $version]));

        return $head.'.'.$body.'.'.self::base64(hash_hmac('sha256', $head.'.'.$body, $this->secret, true));
    }

    /**
     * One token's claims when it is signed with the key and valid now, as
     * scheb's JwtTokenEncoder::decodeToken() accepts it; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            return null;
        }
        [$head, $body, $signature] = $parts;
        $header = self::unjson(self::unbase64($head));
        $claims = self::unjson(self::unbase64($body));
        $signature = self::unbase64($signature);
        if (null === $header || null === $claims || null === $signature || 'HS256' !== ($header['alg'] ?? null) || isset($header['enc'])) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $head.'.'.$body, $this->secret, true), $signature)) {
            return null;
        }
        $now = $this->clock->now();
        foreach (['iat', 'nbf'] as $claim) {
            if (isset($claims[$claim]) && (null === ($at = self::undate($claims[$claim])) || $now < $at)) {
                return null;
            }
        }
        if (isset($claims['exp']) && (null === ($exp = self::undate($claims['exp'])) || $now >= $exp)) {
            return null;
        }

        return $claims;
    }

    /** @return array<string, array<string, mixed>> the cookie's valid tokens, by their encoded value */
    private function tokens(string $cookie): array
    {
        $tokens = [];
        foreach ('' === $cookie ? [] : explode(';', $cookie) as $token) {
            if (null !== ($claims = $this->decode($token))) {
                $tokens[$token] = $claims;
            }
        }

        return $tokens;
    }

    /** lcobucci/jwt's MicrosecondBasedDateConversion: whole seconds as an integer, otherwise a float with the microseconds. */
    private static function date(\DateTimeImmutable $date): int|float
    {
        return '000000' === $date->format('u') ? (int) $date->format('U') : (float) $date->format('U.u');
    }

    private static function undate(mixed $timestamp): ?\DateTimeImmutable
    {
        if (!\is_int($timestamp) && !\is_float($timestamp) && !(\is_string($timestamp) && is_numeric($timestamp))) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('U.u', number_format((float) $timestamp, 6, '.', '')) ?: null;
    }

    /** lcobucci/jwt's JoseEncoder::jsonEncode(). */
    private static function json(array $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private static function unjson(?string $json): ?array
    {
        if (null === $json) {
            return null;
        }
        $data = json_decode($json, true);

        return \is_array($data) ? $data : null;
    }

    private static function base64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unbase64(string $data): ?string
    {
        if (!preg_match('~^[A-Za-z0-9_-]*$~', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
