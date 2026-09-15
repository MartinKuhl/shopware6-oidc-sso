<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * `sw6oidc_user_provider` was created without an `updated_at` column
 * (Migration1730000001CreateOidcSchema) — every other table in this plugin's
 * schema has one. Shopware's EntityDefinition::defaultFields() always adds
 * an UpdatedAtField regardless of what the entity's own defineFields()
 * declares, so the DAL unconditionally selects/writes `updated_at` for this
 * entity too; without the column, every insert/select against
 * sw6oidc_user_provider fails with "Unknown column
 * 'sw6oidc_user_provider.updated_at'" — including the bind-on-first-login
 * write that JIT provisioning depends on.
 */
class Migration1789470000AddUserProviderUpdatedAt extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789470000;
    }

    public function update(Connection $connection): void
    {
        $columnExists = $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sw6oidc_user_provider'
              AND COLUMN_NAME = 'updated_at'
        SQL);

        if ((int) $columnExists > 0) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `sw6oidc_user_provider`
                ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
