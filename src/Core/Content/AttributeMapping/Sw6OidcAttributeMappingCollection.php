<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcAttributeMappingEntity>
 */
class Sw6OidcAttributeMappingCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcAttributeMappingEntity::class;
    }
}
