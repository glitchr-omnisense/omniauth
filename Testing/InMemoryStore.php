<?php

namespace Omniauth\Testing;

use Omniauth\Contract\Accounts;
use Omniauth\Contract\BackupCodes;
use Omniauth\Contract\Identities;
use Omniauth\Contract\LinkTokens;
use Omniauth\Contract\Passwords;
use Omniauth\Contract\PlainLinkTokens;
use Omniauth\Contract\TrustedVersion;
use Omniauth\Exception\AccountRefused;

/**
 * Every store contract in memory, over TestAccount objects: tests, the
 * harness's bare script, a first try in plain PHP. An application fills the
 * same contracts on its tables (omnibase: Base\Auth\). The e-mailed codes and
 * the TOTP secrets, whose contracts share method names, are emailCodes() and
 * totpSecrets(), over the same accounts.
 */
final class InMemoryStore implements Accounts, Passwords, LinkTokens, PlainLinkTokens, BackupCodes, TrustedVersion, Identities
{
    /** @var array<string, TestAccount> */
    private array $accounts = [];

    public function __construct(TestAccount ...$accounts)
    {
        foreach ($accounts as $account) {
            $this->add($account);
        }
    }

    public function add(TestAccount $account): TestAccount
    {
        return $this->accounts[$account->handle] = $account;
    }

    public function emailCodes(): InMemoryEmailCodes
    {
        return new InMemoryEmailCodes();
    }

    public function totpSecrets(): InMemoryTotpSecrets
    {
        return new InMemoryTotpSecrets();
    }

    // Accounts

    public function byIdentifier(string $identifier): ?object
    {
        foreach ($this->accounts as $account) {
            if (0 === strcasecmp($account->identifier, $identifier) || (null !== $account->email && 0 === strcasecmp($account->email, $identifier))) {
                return $account;
            }
        }

        return null;
    }

    public function byHandle(string $handle): ?object
    {
        return $this->accounts[$handle] ?? null;
    }

    public function handle(object $account): string
    {
        return self::of($account)->handle;
    }

    public function identifier(object $account): string
    {
        return self::of($account)->identifier;
    }

    public function displayName(object $account): string
    {
        return self::of($account)->name ?? self::of($account)->identifier;
    }

    public function email(object $account): ?string
    {
        return self::of($account)->email;
    }

    public function check(object $account): void
    {
        if (null !== self::of($account)->refused) {
            throw new AccountRefused(self::of($account)->refused);
        }
    }

    // Passwords

    public function hash(object $account): ?string
    {
        return self::of($account)->password;
    }

    public function update(object $account, string $hash): void
    {
        self::of($account)->password = $hash;
    }

    // LinkTokens, PlainLinkTokens

    public function issue(object $account, string $hash, \DateTimeImmutable $until): void
    {
        self::of($account)->links[$hash] = $until->getTimestamp();
    }

    public function consume(string $hash, \DateTimeImmutable $now): ?object
    {
        return $this->take('links', $hash, $now);
    }

    public function consumePlain(string $value, \DateTimeImmutable $now): ?object
    {
        return $this->take('plainLinks', $value, $now);
    }

    // BackupCodes

    public function hashes(object $account): array
    {
        return self::of($account)->backupCodes;
    }

    public function replace(object $account, array $hashes): void
    {
        self::of($account)->backupCodes = array_values($hashes);
    }

    // TrustedVersion

    public function version(object $account): int
    {
        return self::of($account)->trustedVersion;
    }

    public function revokeAll(object $account): void
    {
        ++self::of($account)->trustedVersion;
    }

    // Identities

    public function owner(string $provider, string $subject): ?object
    {
        foreach ($this->accounts as $account) {
            if (($account->identities[$provider] ?? null) === $subject) {
                return $account;
            }
        }

        return null;
    }

    public function link(object $account, string $provider, string $subject): void
    {
        self::of($account)->identities[$provider] = $subject;
    }

    public function providers(object $account): array
    {
        return array_keys(self::of($account)->identities);
    }

    /** @param 'links'|'plainLinks' $list */
    private function take(string $list, string $key, \DateTimeImmutable $now): ?TestAccount
    {
        foreach ($this->accounts as $account) {
            if (isset($account->{$list}[$key])) {
                $until = $account->{$list}[$key];
                unset($account->{$list}[$key]);

                return $now->getTimestamp() < $until ? $account : null;
            }
        }

        return null;
    }

    /** @internal */
    public static function of(object $account): TestAccount
    {
        return $account instanceof TestAccount ? $account : throw new \InvalidArgumentException(\sprintf('InMemoryStore holds TestAccount objects, not %s.', $account::class));
    }
}
