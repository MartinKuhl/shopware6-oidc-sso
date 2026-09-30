<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790800003CreateSessionActivitySchema extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790800003;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_session_activity` (
                `id` BINARY(16) NOT NULL,
                `provider_id` BINARY(16) NULL,
                `user_type` VARCHAR(16) NOT NULL,
                `user_id` BINARY(16) NOT NULL,
                `sub` VARCHAR(255) NULL,
                `sid` VARCHAR(255) NULL,
                `login_method` VARCHAR(16) NOT NULL,
                `session_key_hash` CHAR(64) NULL,
                `registry_session_id` VARCHAR(64) NULL,
                `ip_address` VARCHAR(45) NULL,
                `user_agent` VARCHAR(512) NULL,
                `logged_in_at` DATETIME(3) NOT NULL,
                `logged_out_at` DATETIME(3) NULL,
                `logout_reason` VARCHAR(32) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.sw6oidc_session_activity.user` (`user_type`, `user_id`),
                KEY `idx.sw6oidc_session_activity.logged_in_at` (`logged_in_at`),
                KEY `idx.sw6oidc_session_activity.session_key_hash` (`session_key_hash`),
                KEY `idx.sw6oidc_session_activity.registry_session_id` (`registry_session_id`),
                KEY `fk.sw6oidc_session_activity.provider_id` (`provider_id`),
                CONSTRAINT `fk.sw6oidc_session_activity.provider_id` FOREIGN KEY (`provider_id`)
                    REFERENCES `sw6oidc_provider` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
