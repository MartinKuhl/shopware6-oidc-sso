<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\SessionActivity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcSessionActivityEntity>
 */
class Sw6OidcSessionActivityCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcSessionActivityEntity::class;
    }
}
