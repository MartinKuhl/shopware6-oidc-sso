<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcAccessControlRuleEntity>
 */
class Sw6OidcAccessControlRuleCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcAccessControlRuleEntity::class;
    }
}
