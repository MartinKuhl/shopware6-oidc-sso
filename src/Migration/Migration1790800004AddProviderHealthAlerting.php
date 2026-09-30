<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Health alerting per provider: admin settings (webhook URL — encrypted, it
 * usually embeds a token —, failure threshold where 0 = off, recovery notice)
 * plus runtime state owned by HealthCheckAlertTaskHandler.
 */
class Migration1790800004AddProviderHealthAlerting extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790800004;
    }

    public function update(Connection $connection): void
    {
        $existing = array_map(strtolower(...), $connection->fetchFirstColumn('SHOW COLUMNS FROM `sw6oidc_provider`'));

        $columns = [
            'health_alert_webhook_url' => 'VARCHAR(2048) NULL',
            'health_alert_failure_threshold' => 'INT(11) NOT NULL DEFAULT 0',
            'health_alert_notify_on_recovery' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'health_alert_consecutive_failures' => 'INT(11) NOT NULL DEFAULT 0',
            'health_alert_last_status' => 'VARCHAR(16) NULL',
            'health_alert_last_checked_at' => 'DATETIME(3) NULL',
            'health_alert_first_failure_at' => 'DATETIME(3) NULL',
            'health_alert_last_notified_at' => 'DATETIME(3) NULL',
        ];

        foreach ($columns as $name => $definition) {
            if (!\in_array($name, $existing, true)) {
                $connection->executeStatement(sprintf('ALTER TABLE `sw6oidc_provider` ADD COLUMN `%s` %s', $name, $definition));
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes.
    }
}
