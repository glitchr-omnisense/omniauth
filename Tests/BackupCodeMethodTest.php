<?php

namespace Omniauth\Tests;

use Omniauth\Factor;
use Omniauth\Failure;
use Omniauth\Status;
use Omniauth\Tests\Fixtures\Kit;
use PHPUnit\Framework\TestCase;

final class BackupCodeMethodTest extends TestCase
{
    public function testBackupCodesAloneAskForNoSecondFactor(): void
    {
        $kit = new Kit();
        $kit->ringo->backupCodes = [password_hash('A1B2C3-D4E5F6', \PASSWORD_BCRYPT, ['cost' => 4])];

        self::assertTrue($kit->omniauth->verify('password', Kit::context(), ['identifier' => 'ringo', 'password' => 'yellow-submarine'])->isAuthenticated());
    }

    public function testABackupCodeStandsInForTheOtherSecondFactorOnce(): void
    {
        $kit = new Kit();
        $kit->paul->backupCodes = [password_hash('A1B2C3-D4E5F6', \PASSWORD_BCRYPT, ['cost' => 4]), password_hash('0F0F0F-1E1E1E', \PASSWORD_BCRYPT, ['cost' => 4])];
        $first = $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);

        self::assertSame([Factor::EmailCode, Factor::BackupCode], $first->offered);
        self::assertSame(Failure::BadCode, $kit->omniauth->verify('backup-code', Kit::context(), ['code' => 'FFFFFF-FFFFFF'])->failure);
        $result = $kit->omniauth->verify('backup-code', Kit::context(), ['code' => ' a1b2c3-d4e5f6 ']);
        self::assertSame(Status::Authenticated, $result->status);
        self::assertSame([Factor::Password, Factor::BackupCode], $result->passed);
        self::assertCount(1, $kit->paul->backupCodes, 'the one that served is gone');

        $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be']);
        self::assertSame(Failure::BadCode, $kit->omniauth->verify('backup-code', Kit::context(), ['code' => 'A1B2C3-D4E5F6'])->failure, 'once');
        self::assertTrue($kit->omniauth->verify('backup-code', Kit::context(), ['code' => '0F0F0F-1E1E1E'])->isAuthenticated());
    }

    /** The hashes omnibase's User::setBackupCodes() writes (password_hash, PASSWORD_DEFAULT) are read as they are. */
    public function testEnrolmentDrawsEightCodesShownOnceAndKeepsTheirHashes(): void
    {
        $kit = new Kit();
        $enrolled = $kit->omniauth->enrol('backup-code', $kit->paul)->confirm(Kit::context());

        self::assertTrue($enrolled->isDone());
        self::assertCount(8, $enrolled->secrets);
        self::assertCount(8, array_unique($enrolled->secrets));
        foreach ($enrolled->secrets as $i => $code) {
            self::assertMatchesRegularExpression('~^[0-9A-F]{6}-[0-9A-F]{6}$~', $code);
            self::assertTrue(password_verify($code, $kit->paul->backupCodes[$i]));
        }
        self::assertSame(0, $kit->paul->trustedVersion, 'new codes: the devices stay trusted');

        $again = $kit->omniauth->enrol('backup-code', $kit->paul)->confirm(Kit::context());
        self::assertFalse(password_verify($enrolled->secrets[0], $kit->paul->backupCodes[0]), 'a new set replaces the former');
        self::assertCount(8, $again->secrets);
    }

    public function testTurningThemOff(): void
    {
        $kit = new Kit();
        $kit->omniauth->enrol('backup-code', $kit->paul)->confirm(Kit::context());
        $kit->omniauth->revoke('backup-code', $kit->paul);

        self::assertSame([], $kit->paul->backupCodes);
        self::assertSame([Factor::EmailCode], $kit->omniauth->verify('password', Kit::context(), ['identifier' => 'paul', 'password' => 'let-it-be'])->offered);
    }
}
