<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\RoleMapping;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcRoleMappingEntity>
 */
class Sw6OidcRoleMappingCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcRoleMappingEntity::class;
    }
}
