<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\PasskeyCredential;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<Sw6OidcPasskeyCredentialEntity>
 */
class Sw6OidcPasskeyCredentialCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return Sw6OidcPasskeyCredentialEntity::class;
    }
}
