<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\UserProvider;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Binds a Shopware `user` (admin) or `customer` id to an IdP identity: the
 * provider plus the subject (`iss` + `sub`) it authenticated. Logins look
 * accounts up by that subject; `sub` is NULL only on legacy bindings created
 * before subject binding existed (backfilled on the next verified login).
 */
class Sw6OidcUserProviderDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'sw6oidc_user_provider';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return Sw6OidcUserProviderEntity::class;
    }

    public function getCollectionClass(): string
    {
        return Sw6OidcUserProviderCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('user_type', 'userType', 16))->addFlags(new ApiAware(), new Required()),
            (new IdField('user_id', 'userId'))->addFlags(new ApiAware(), new Required()),
            (new FkField('provider_id', 'providerId', Sw6OidcProviderDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new StringField('issuer', 'issuer', 2048))->addFlags(new ApiAware()),
            (new StringField('sub', 'sub', 255))->addFlags(new ApiAware()),
            new CreatedAtField(),

            (new ManyToOneAssociationField('provider', 'provider_id', Sw6OidcProviderDefinition::class, 'id'))
                ->addFlags(new ApiAware()),
        ]);
    }
}
