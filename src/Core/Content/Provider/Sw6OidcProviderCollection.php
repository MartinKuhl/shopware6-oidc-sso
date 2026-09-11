<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\Provider;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcProviderEntity>
 */
class Sw6OidcProviderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcProviderEntity::class;
    }
}
