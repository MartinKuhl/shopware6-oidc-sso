<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\RoleMapping;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class Sw6OidcRoleMappingEntity extends Entity
{
    use EntityIdTrait;

    protected string $providerId;
    /** 'admin_role'|'customer_group'|'superadmin' */
    protected string $mappingType;
    protected string $oidcGroup;
    protected ?string $aclRoleId = null;
    protected ?string $customerGroupId = null;
    protected int $sortOrder = 0;

    protected ?Sw6OidcProviderEntity $provider = null;
    protected ?AclRoleEntity $aclRole = null;
    protected ?CustomerGroupEntity $customerGroup = null;

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getMappingType(): string
    {
        return $this->mappingType;
    }

    public function setMappingType(string $mappingType): void
    {
        $this->mappingType = $mappingType;
    }

    public function getOidcGroup(): string
    {
        return $this->oidcGroup;
    }

    public function setOidcGroup(string $oidcGroup): void
    {
        $this->oidcGroup = $oidcGroup;
    }

    public function getAclRoleId(): ?string
    {
        return $this->aclRoleId;
    }

    public function setAclRoleId(?string $aclRoleId): void
    {
        $this->aclRoleId = $aclRoleId;
    }

    public function getCustomerGroupId(): ?string
    {
        return $this->customerGroupId;
    }

    public function setCustomerGroupId(?string $customerGroupId): void
    {
        $this->customerGroupId = $customerGroupId;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getProvider(): ?Sw6OidcProviderEntity
    {
        return $this->provider;
    }

    public function setProvider(?Sw6OidcProviderEntity $provider): void
    {
        $this->provider = $provider;
    }

    public function getAclRole(): ?AclRoleEntity
    {
        return $this->aclRole;
    }

    public function setAclRole(?AclRoleEntity $aclRole): void
    {
        $this->aclRole = $aclRole;
    }

    public function getCustomerGroup(): ?CustomerGroupEntity
    {
        return $this->customerGroup;
    }

    public function setCustomerGroup(?CustomerGroupEntity $customerGroup): void
    {
        $this->customerGroup = $customerGroup;
    }
}
