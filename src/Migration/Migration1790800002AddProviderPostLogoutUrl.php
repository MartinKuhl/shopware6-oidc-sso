<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790800002AddProviderPostLogoutUrl extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790800002;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `sw6oidc_provider` LIKE \'post_logout_url\'');

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `sw6oidc_provider`
                ADD COLUMN `post_logout_url` VARCHAR(1024) NULL AFTER `end_session_endpoint`
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
