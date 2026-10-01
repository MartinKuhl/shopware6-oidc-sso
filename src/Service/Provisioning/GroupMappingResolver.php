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
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves OIDC group claims to an ACL role (admin) or customer group
 * (storefront) via sw6oidc_role_mapping, case-insensitive, first match by
 * sort_order wins, falling back to the provider's configured default
 * (mapping table -> default -> deny).
 */
class GroupMappingResolver implements ResetInterface
{
    /** @var array<string, list<Sw6OidcRoleMappingEntity>> */
    private array $mappingsByProvider = [];

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
     * Whether any 'superadmin' mapping row for this provider matches the
     * user's OIDC groups. Deliberately no default/fallback here (unlike
     * resolveAclRoleId/resolveCustomerGroupId) — a superadmin grant must
     * always come from an explicit group match, never an implicit default.
     *
     * @param string[] $oidcGroups
     */
    public function matchesSuperadminGroup(Sw6OidcProviderEntity $provider, array $oidcGroups, Context $context): bool
    {
        if ($oidcGroups === []) {
            return false;
        }

        $normalizedGroups = array_map(mb_strtolower(...), $oidcGroups);

        foreach ($this->mappings($provider->getId(), Sw6OidcRoleMappingDefinition::MAPPING_TYPE_SUPERADMIN, $context) as $mapping) {
            if (\in_array(mb_strtolower($mapping->getOidcGroup()), $normalizedGroups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $oidcGroups
     */
    private function resolve(string $providerId, string $mappingType, array $oidcGroups, Context $context): ?string
    {
        if ($oidcGroups === []) {
            return null;
        }

        $normalizedGroups = array_map(mb_strtolower(...), $oidcGroups);

        foreach ($this->mappings($providerId, $mappingType, $context) as $mapping) {
            if (!\in_array(mb_strtolower($mapping->getOidcGroup()), $normalizedGroups, true)) {
                continue;
            }

            return $mappingType === Sw6OidcRoleMappingDefinition::MAPPING_TYPE_ADMIN_ROLE
                ? $mapping->getAclRoleId()
                : $mapping->getCustomerGroupId();
        }

        return null;
    }

    public function reset(): void
    {
        $this->mappingsByProvider = [];
    }

    /**
     * All of a provider's mapping rows in sort order, loaded once per
     * request: role resolution and the superadmin check used to query
     * separately on every login (L8).
     *
     * @return list<Sw6OidcRoleMappingEntity>
     */
    private function mappings(string $providerId, string $mappingType, Context $context): array
    {
        if (!isset($this->mappingsByProvider[$providerId])) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('providerId', $providerId));
            $criteria->addSorting(new FieldSorting('sortOrder', FieldSorting::ASCENDING));

            $this->mappingsByProvider[$providerId] = array_values(array_filter(
                $this->roleMappingRepository->search($criteria, $context)->getEntities()->getElements(),
                static fn (mixed $mapping): bool => $mapping instanceof Sw6OidcRoleMappingEntity,
            ));
        }

        return array_values(array_filter(
            $this->mappingsByProvider[$providerId],
            static fn (Sw6OidcRoleMappingEntity $mapping): bool => $mapping->getMappingType() === $mappingType,
        ));
    }
}
