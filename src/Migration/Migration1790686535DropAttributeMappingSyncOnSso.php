<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Drops the per-attribute sw6oidc_attribute_mapping.sync_on_sso column: it was
 * never read by any code (re-sync is gated only by the per-provider
 * sync_*_on_sso toggles) and its UI toggle was already removed. The entity no
 * longer maps it, and the column has DEFAULT 0, so inserts keep working
 * between the non-destructive and destructive migration runs.
 */
class Migration1790686535DropAttributeMappingSyncOnSso extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790686535;
    }

    public function update(Connection $connection): void
    {
        // Nothing non-destructive to do.
    }

    public function updateDestructive(Connection $connection): void
    {
        $this->dropColumnIfExists($connection, 'sw6oidc_attribute_mapping', 'sync_on_sso');
    }
}
