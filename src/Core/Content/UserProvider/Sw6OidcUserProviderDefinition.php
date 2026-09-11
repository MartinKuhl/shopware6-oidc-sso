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
 * Binds a Shopware `user` (admin) or `customer` id to the OIDC provider that first
 * authenticated them. Enforces per-user IdP binding at login and doubles as the
 * session-activity log (see plan: mirrors m2oidc_oauth_user_provider).
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
            new CreatedAtField(),

            (new ManyToOneAssociationField('provider', 'provider_id', Sw6OidcProviderDefinition::class, 'id'))
                ->addFlags(new ApiAware()),
        ]);
    }
}
