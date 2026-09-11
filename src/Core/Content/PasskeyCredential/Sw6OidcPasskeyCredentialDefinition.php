<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\PasskeyCredential;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * One WebAuthn credential per admin/customer (see plan: mirrors m2oidc_passkey_credentials).
 * userType/userId is the same deliberately-polymorphic pattern as Sw6OidcUserProviderDefinition.
 */
class Sw6OidcPasskeyCredentialDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'sw6oidc_passkey_credential';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return Sw6OidcPasskeyCredentialEntity::class;
    }

    public function getCollectionClass(): string
    {
        return Sw6OidcPasskeyCredentialCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('user_type', 'userType', 16))->addFlags(new ApiAware(), new Required()),
            (new IdField('user_id', 'userId'))->addFlags(new ApiAware(), new Required()),
            (new StringField('credential_id', 'credentialId'))->addFlags(new ApiAware(), new Required()),
            (new LongTextField('public_key', 'publicKey'))->addFlags(new ApiAware(), new Required()),
            (new IntField('sign_count', 'signCount'))->addFlags(new ApiAware()),
            (new StringField('user_handle', 'userHandle'))->addFlags(new ApiAware(), new Required()),
            (new StringField('nickname', 'nickname'))->addFlags(new ApiAware()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
