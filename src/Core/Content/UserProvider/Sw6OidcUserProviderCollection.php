<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\UserProvider;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcUserProviderEntity>
 */
class Sw6OidcUserProviderCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcUserProviderEntity::class;
    }
}
