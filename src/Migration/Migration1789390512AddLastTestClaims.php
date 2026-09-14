<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1789390512AddLastTestClaims extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789390512;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `sw6oidc_provider`
                ADD COLUMN `last_test_claims` JSON NULL AFTER `last_test_at`
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
