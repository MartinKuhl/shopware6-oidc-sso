<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * One claims-based login gate per row, AND-combined per provider (see
 * Sw6OidcAccessControlEvaluator for the matching semantics).
 */
class Sw6OidcAccessControlRuleDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'sw6oidc_access_control_rule';

    public const OPERATOR_EQ = 'eq';
    public const OPERATOR_NEQ = 'neq';
    public const OPERATOR_CONTAINS = 'contains';
    public const OPERATOR_NOT_CONTAINS = 'not_contains';
    public const OPERATOR_EXISTS = 'exists';
    public const OPERATOR_NOT_EXISTS = 'not_exists';

    public const OPERATORS = [
        self::OPERATOR_EQ,
        self::OPERATOR_NEQ,
        self::OPERATOR_CONTAINS,
        self::OPERATOR_NOT_CONTAINS,
        self::OPERATOR_EXISTS,
        self::OPERATOR_NOT_EXISTS,
    ];

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return Sw6OidcAccessControlRuleEntity::class;
    }

    public function getCollectionClass(): string
    {
        return Sw6OidcAccessControlRuleCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('provider_id', 'providerId', Sw6OidcProviderDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new StringField('claim_key', 'claimKey'))->addFlags(new ApiAware(), new Required()),
            (new StringField('operator', 'operator', 16))->addFlags(new ApiAware(), new Required()),
            (new StringField('value', 'value', 1024))->addFlags(new ApiAware()),
            (new StringField('error_message', 'errorMessage', 1024))->addFlags(new ApiAware()),
            (new IntField('sort_order', 'sortOrder'))->addFlags(new ApiAware()),
            new CreatedAtField(),
            new UpdatedAtField(),

            (new ManyToOneAssociationField('provider', 'provider_id', Sw6OidcProviderDefinition::class, 'id'))
                ->addFlags(new ApiAware()),
        ]);
    }
}
