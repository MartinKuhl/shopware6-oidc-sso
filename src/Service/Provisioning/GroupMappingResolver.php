<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Service\Provisioning;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Core\Content\RoleMapping\Sw6OidcRoleMappingDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\RoleMapping\Sw6OidcRoleMappingEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Resolves OIDC group claims to an ACL role (admin) or customer group
 * (storefront) via sw6oidc_role_mapping, case-insensitive, first match by
 * sort_order wins, falling back to the provider's configured default — mirrors
 * the Magento module's Model/Service/GroupMappingResolver.php fallback chain
 * (normalized table -> default -> deny).
 */
class GroupMappingResolver
{
    public function __construct(private readonly EntityRepository $roleMappingRepository)
    {
    }

    /**
     * @param string[] $oidcGroups
     */
    public function resolveAclRoleId(Sw6OidcProviderEntity $provider, array $oidcGroups, Context $context): ?string
    {
        return $this->resolve($provider->getId(), Sw6OidcRoleMappingDefinition::MAPPING_TYPE_ADMIN_ROLE, $oidcGroups, $context)
            ?? $provider->getDefaultAclRoleId();
    }

    /**
     * @param string[] $oidcGroups
     */
    public function resolveCustomerGroupId(Sw6OidcProviderEntity $provider, array $oidcGroups, Context $context): ?string
    {
        return $this->resolve($provider->getId(), Sw6OidcRoleMappingDefinition::MAPPING_TYPE_CUSTOMER_GROUP, $oidcGroups, $context)
            ?? $provider->getDefaultCustomerGroupId();
    }

    /**
     * @param string[] $oidcGroups
     */
    private function resolve(string $providerId, string $mappingType, array $oidcGroups, Context $context): ?string
    {
        if ($oidcGroups === []) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('providerId', $providerId));
        $criteria->addFilter(new EqualsFilter('mappingType', $mappingType));
        $criteria->addSorting(new FieldSorting('sortOrder', FieldSorting::ASCENDING));

        $normalizedGroups = array_map(mb_strtolower(...), $oidcGroups);

        foreach ($this->roleMappingRepository->search($criteria, $context)->getEntities() as $mapping) {
            \assert($mapping instanceof Sw6OidcRoleMappingEntity);

            if (!\in_array(mb_strtolower($mapping->getOidcGroup()), $normalizedGroups, true)) {
                continue;
            }

            return $mappingType === Sw6OidcRoleMappingDefinition::MAPPING_TYPE_ADMIN_ROLE
                ? $mapping->getAclRoleId()
                : $mapping->getCustomerGroupId();
        }

        return null;
    }
}
