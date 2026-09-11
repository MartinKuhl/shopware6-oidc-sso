<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000001CreateOidcSchema extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000001;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_provider` (
                `id` BINARY(16) NOT NULL,
                `app_name` VARCHAR(255) NOT NULL,
                `display_name` VARCHAR(255) NULL,
                `client_id` VARCHAR(255) NOT NULL,
                `client_secret` VARCHAR(1024) NOT NULL,
                `authorize_endpoint` VARCHAR(1024) NULL,
                `access_token_endpoint` VARCHAR(1024) NULL,
                `user_info_endpoint` VARCHAR(1024) NULL,
                `end_session_endpoint` VARCHAR(1024) NULL,
                `revocation_endpoint` VARCHAR(1024) NULL,
                `jwks_endpoint` VARCHAR(1024) NULL,
                `issuer` VARCHAR(1024) NULL,
                `well_known_config_url` VARCHAR(1024) NULL,
                `scope` VARCHAR(512) NOT NULL DEFAULT 'openid profile email',
                `pkce_flow` VARCHAR(16) NOT NULL DEFAULT 'S256',
                `claim_encoding` VARCHAR(16) NOT NULL DEFAULT 'none',
                `public_client` TINYINT(1) NOT NULL DEFAULT 0,
                `group_attribute` VARCHAR(255) NOT NULL DEFAULT 'groups',
                `auto_create_customer` TINYINT(1) NOT NULL DEFAULT 1,
                `auto_create_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `disable_non_oidc_admin_login` TINYINT(1) NOT NULL DEFAULT 0,
                `disable_non_oidc_customer_login` TINYINT(1) NOT NULL DEFAULT 0,
                `show_customer_link` TINYINT(1) NOT NULL DEFAULT 1,
                `show_admin_link` TINYINT(1) NOT NULL DEFAULT 1,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `login_type` VARCHAR(16) NOT NULL DEFAULT 'both',
                `sort_order` INT(11) NOT NULL DEFAULT 0,
                `button_label` VARCHAR(255) NULL,
                `button_color` VARCHAR(16) NULL,
                `sync_customer_profile_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `sync_customer_address_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `sync_customer_group_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `sync_admin_profile_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `sync_admin_role_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `http_timeout` SMALLINT NOT NULL DEFAULT 30,
                `jwks_cache_ttl` INT(11) NOT NULL DEFAULT 86400,
                `default_customer_group_id` BINARY(16) NULL,
                `default_acl_role_id` BINARY(16) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.sw6oidc_provider.app_name` (`app_name`),
                KEY `fk.sw6oidc_provider.default_customer_group_id` (`default_customer_group_id`),
                KEY `fk.sw6oidc_provider.default_acl_role_id` (`default_acl_role_id`),
                CONSTRAINT `fk.sw6oidc_provider.default_customer_group_id` FOREIGN KEY (`default_customer_group_id`)
                    REFERENCES `customer_group` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.sw6oidc_provider.default_acl_role_id` FOREIGN KEY (`default_acl_role_id`)
                    REFERENCES `acl_role` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_attribute_mapping` (
                `id` BINARY(16) NOT NULL,
                `provider_id` BINARY(16) NOT NULL,
                `attribute_type` VARCHAR(64) NOT NULL,
                `attribute_name` VARCHAR(255) NOT NULL,
                `sync_on_sso` TINYINT(1) NOT NULL DEFAULT 0,
                `transform_function` VARCHAR(32) NULL,
                `transform_params` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.sw6oidc_attribute_mapping.provider_type` (`provider_id`, `attribute_type`),
                CONSTRAINT `fk.sw6oidc_attribute_mapping.provider_id` FOREIGN KEY (`provider_id`)
                    REFERENCES `sw6oidc_provider` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_role_mapping` (
                `id` BINARY(16) NOT NULL,
                `provider_id` BINARY(16) NOT NULL,
                `mapping_type` VARCHAR(32) NOT NULL,
                `oidc_group` VARCHAR(255) NOT NULL,
                `acl_role_id` BINARY(16) NULL,
                `customer_group_id` BINARY(16) NULL,
                `sort_order` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `fk.sw6oidc_role_mapping.provider_id` (`provider_id`),
                KEY `fk.sw6oidc_role_mapping.acl_role_id` (`acl_role_id`),
                KEY `fk.sw6oidc_role_mapping.customer_group_id` (`customer_group_id`),
                CONSTRAINT `fk.sw6oidc_role_mapping.provider_id` FOREIGN KEY (`provider_id`)
                    REFERENCES `sw6oidc_provider` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.sw6oidc_role_mapping.acl_role_id` FOREIGN KEY (`acl_role_id`)
                    REFERENCES `acl_role` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.sw6oidc_role_mapping.customer_group_id` FOREIGN KEY (`customer_group_id`)
                    REFERENCES `customer_group` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_user_provider` (
                `id` BINARY(16) NOT NULL,
                `user_type` VARCHAR(16) NOT NULL,
                `user_id` BINARY(16) NOT NULL,
                `provider_id` BINARY(16) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.sw6oidc_user_provider.user` (`user_type`, `user_id`),
                KEY `fk.sw6oidc_user_provider.provider_id` (`provider_id`),
                CONSTRAINT `fk.sw6oidc_user_provider.provider_id` FOREIGN KEY (`provider_id`)
                    REFERENCES `sw6oidc_provider` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `sw6oidc_passkey_credential` (
                `id` BINARY(16) NOT NULL,
                `user_type` VARCHAR(16) NOT NULL,
                `user_id` BINARY(16) NOT NULL,
                `credential_id` VARCHAR(255) NOT NULL,
                `public_key` LONGTEXT NOT NULL,
                `sign_count` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `user_handle` VARCHAR(255) NOT NULL,
                `nickname` VARCHAR(255) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.sw6oidc_passkey_credential.credential_id` (`credential_id`),
                KEY `idx.sw6oidc_passkey_credential.user` (`user_type`, `user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes for the initial schema.
    }
}
