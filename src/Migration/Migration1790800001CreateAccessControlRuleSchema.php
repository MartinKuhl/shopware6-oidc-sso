<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790800001CreateAccessControlRuleSchema extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790800001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_access_control_rule` (
                `id` BINARY(16) NOT NULL,
                `provider_id` BINARY(16) NOT NULL,
                `claim_key` VARCHAR(255) NOT NULL,
                `operator` VARCHAR(16) NOT NULL,
                `value` VARCHAR(1024) NULL,
                `error_message` VARCHAR(1024) NULL,
                `sort_order` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.sw6oidc_access_control_rule.provider_id` (`provider_id`),
                CONSTRAINT `fk.sw6oidc_access_control_rule.provider_id` FOREIGN KEY (`provider_id`)
                    REFERENCES `sw6oidc_provider` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
