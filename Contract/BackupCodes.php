<?php

namespace Omniauth\Contract;

/** The backup codes' hashes, password_hash()'s (omnibase: user.backupCodes, a JSON list). */
interface BackupCodes
{
    /** @return list<string> */
    public function hashes(object $account): array;

    /** @param list<string> $hashes */
    public function replace(object $account, array $hashes): void;
}
