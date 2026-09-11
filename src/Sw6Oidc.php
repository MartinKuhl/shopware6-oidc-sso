<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class Sw6Oidc extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('
            DROP TABLE IF EXISTS `sw6oidc_passkey_credential`;
            DROP TABLE IF EXISTS `sw6oidc_user_provider`;
            DROP TABLE IF EXISTS `sw6oidc_role_mapping`;
            DROP TABLE IF EXISTS `sw6oidc_attribute_mapping`;
            DROP TABLE IF EXISTS `sw6oidc_provider`;
        ');
    }
}
