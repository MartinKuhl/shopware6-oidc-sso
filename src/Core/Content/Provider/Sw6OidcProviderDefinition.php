<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\Provider;

use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\RoleMapping\Sw6OidcRoleMappingDefinition;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupDefinition;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class Sw6OidcProviderDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'sw6oidc_provider';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return Sw6OidcProviderEntity::class;
    }

    public function getCollectionClass(): string
    {
        return Sw6OidcProviderCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('app_name', 'appName'))->addFlags(new ApiAware(), new Required()),
            (new StringField('display_name', 'displayName'))->addFlags(new ApiAware()),
            (new StringField('client_id', 'clientId'))->addFlags(new ApiAware(), new Required()),
            // TODO(later phase): encrypt at rest via a custom FieldSerializer, mirroring the Magento module's
            // EncryptorInterface-backed client_secret column, instead of storing plaintext.
            (new StringField('client_secret', 'clientSecret', 1024))->addFlags(new ApiAware(), new Required()),
            (new StringField('authorize_endpoint', 'authorizeEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('access_token_endpoint', 'accessTokenEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('user_info_endpoint', 'userInfoEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('end_session_endpoint', 'endSessionEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('revocation_endpoint', 'revocationEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('jwks_endpoint', 'jwksEndpoint', 1024))->addFlags(new ApiAware()),
            (new StringField('issuer', 'issuer', 1024))->addFlags(new ApiAware()),
            (new StringField('well_known_config_url', 'wellKnownConfigUrl', 1024))->addFlags(new ApiAware()),
            (new StringField('scope', 'scope', 512))->addFlags(new ApiAware(), new Required()),
            (new StringField('pkce_flow', 'pkceFlow'))->addFlags(new ApiAware(), new Required()),
            (new StringField('claim_encoding', 'claimEncoding'))->addFlags(new ApiAware(), new Required()),
            (new BoolField('public_client', 'publicClient'))->addFlags(new ApiAware()),
            (new StringField('group_attribute', 'groupAttribute'))->addFlags(new ApiAware(), new Required()),
            (new BoolField('auto_create_customer', 'autoCreateCustomer'))->addFlags(new ApiAware()),
            (new BoolField('auto_create_admin', 'autoCreateAdmin'))->addFlags(new ApiAware()),
            (new BoolField('disable_non_oidc_admin_login', 'disableNonOidcAdminLogin'))->addFlags(new ApiAware()),
            (new BoolField('disable_non_oidc_customer_login', 'disableNonOidcCustomerLogin'))->addFlags(new ApiAware()),
            (new BoolField('show_customer_link', 'showCustomerLink'))->addFlags(new ApiAware()),
            (new BoolField('show_admin_link', 'showAdminLink'))->addFlags(new ApiAware()),
            (new BoolField('is_active', 'isActive'))->addFlags(new ApiAware()),
            (new StringField('login_type', 'loginType'))->addFlags(new ApiAware(), new Required()),
            (new IntField('sort_order', 'sortOrder'))->addFlags(new ApiAware()),
            (new StringField('button_label', 'buttonLabel'))->addFlags(new ApiAware()),
            (new StringField('button_color', 'buttonColor'))->addFlags(new ApiAware()),
            (new BoolField('sync_customer_profile_on_sso', 'syncCustomerProfileOnSso'))->addFlags(new ApiAware()),
            (new BoolField('sync_customer_address_on_sso', 'syncCustomerAddressOnSso'))->addFlags(new ApiAware()),
            (new BoolField('sync_customer_group_on_sso', 'syncCustomerGroupOnSso'))->addFlags(new ApiAware()),
            (new BoolField('sync_admin_profile_on_sso', 'syncAdminProfileOnSso'))->addFlags(new ApiAware()),
            (new BoolField('sync_admin_role_on_sso', 'syncAdminRoleOnSso'))->addFlags(new ApiAware()),
            (new IntField('http_timeout', 'httpTimeout'))->addFlags(new ApiAware()),
            (new IntField('jwks_cache_ttl', 'jwksCacheTtl'))->addFlags(new ApiAware()),
            (new StringField('last_test_status', 'lastTestStatus', 16))->addFlags(new ApiAware()),
            (new DateTimeField('last_test_at', 'lastTestAt'))->addFlags(new ApiAware()),
            (new FkField('default_customer_group_id', 'defaultCustomerGroupId', CustomerGroupDefinition::class))->addFlags(new ApiAware()),
            (new FkField('default_acl_role_id', 'defaultAclRoleId', AclRoleDefinition::class))->addFlags(new ApiAware()),
            new CreatedAtField(),
            new UpdatedAtField(),

            (new OneToManyAssociationField('attributeMappings', Sw6OidcAttributeMappingDefinition::class, 'provider_id'))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new OneToManyAssociationField('roleMappings', Sw6OidcRoleMappingDefinition::class, 'provider_id'))
                ->addFlags(new ApiAware(), new CascadeDelete()),
            (new ManyToOneAssociationField('defaultCustomerGroup', 'default_customer_group_id', CustomerGroupDefinition::class, 'id'))
                ->addFlags(new ApiAware()),
            (new ManyToOneAssociationField('defaultAclRole', 'default_acl_role_id', AclRoleDefinition::class, 'id'))
                ->addFlags(new ApiAware()),
        ]);
    }
}
