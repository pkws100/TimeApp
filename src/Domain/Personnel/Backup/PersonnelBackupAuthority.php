<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

final class PersonnelBackupAuthority
{
    private function __construct(private readonly int $operatorUid)
    {
    }

    public static function forLocalOperator(int $operatorUid): self
    {
        $authority = new self($operatorUid);
        $authority->assertAllowed();

        return $authority;
    }

    public function assertAllowed(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== $this->operatorUid) {
            throw new PersonnelBackupFailure('operator_denied');
        }
    }
}
