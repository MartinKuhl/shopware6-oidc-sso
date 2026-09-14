<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1789383427AddSuperadminGroupMapping extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789383427;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `sw6oidc_provider`
                ADD COLUMN `allow_superadmin_group_mapping` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sync_admin_role_on_sso`
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
