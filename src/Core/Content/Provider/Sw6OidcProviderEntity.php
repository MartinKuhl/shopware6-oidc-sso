<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\Provider;

use MartinKuhl\Sw6Oidc\Core\Content\AttributeMapping\Sw6OidcAttributeMappingCollection;
use MartinKuhl\Sw6Oidc\Core\Content\RoleMapping\Sw6OidcRoleMappingCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupEntity;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class Sw6OidcProviderEntity extends Entity
{
    use EntityIdTrait;

    protected string $appName;
    protected ?string $displayName = null;
    protected string $clientId;
    protected string $clientSecret;
    protected ?string $authorizeEndpoint = null;
    protected ?string $accessTokenEndpoint = null;
    protected ?string $userInfoEndpoint = null;
    protected ?string $endSessionEndpoint = null;
    protected ?string $revocationEndpoint = null;
    protected ?string $jwksEndpoint = null;
    protected ?string $issuer = null;
    protected ?string $wellKnownConfigUrl = null;
    protected string $scope = 'openid profile email';
    protected string $pkceFlow = 'S256';
    protected string $claimEncoding = 'none';
    protected bool $publicClient = false;
    protected string $groupAttribute = 'groups';
    protected bool $autoCreateCustomer = true;
    protected bool $autoCreateAdmin = false;
    protected bool $disableNonOidcAdminLogin = false;
    protected bool $disableNonOidcCustomerLogin = false;
    protected bool $showCustomerLink = true;
    protected bool $showAdminLink = true;
    protected bool $isActive = true;
    /** 'customer'|'admin'|'both' */
    protected string $loginType = 'both';
    protected int $sortOrder = 0;
    protected ?string $buttonLabel = null;
    protected ?string $buttonColor = null;
    protected bool $syncCustomerProfileOnSso = false;
    protected bool $syncCustomerAddressOnSso = false;
    protected bool $syncCustomerGroupOnSso = false;
    protected bool $syncAdminProfileOnSso = false;
    protected bool $syncAdminRoleOnSso = false;
    protected int $httpTimeout = 30;
    protected int $jwksCacheTtl = 86400;
    protected ?string $defaultCustomerGroupId = null;
    protected ?string $defaultAclRoleId = null;

    protected ?Sw6OidcAttributeMappingCollection $attributeMappings = null;
    protected ?Sw6OidcRoleMappingCollection $roleMappings = null;
    protected ?CustomerGroupEntity $defaultCustomerGroup = null;
    protected ?AclRoleEntity $defaultAclRole = null;

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function setAppName(string $appName): void
    {
        $this->appName = $appName;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): void
    {
        $this->clientId = $clientId;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): void
    {
        $this->clientSecret = $clientSecret;
    }

    public function getAuthorizeEndpoint(): ?string
    {
        return $this->authorizeEndpoint;
    }

    public function setAuthorizeEndpoint(?string $authorizeEndpoint): void
    {
        $this->authorizeEndpoint = $authorizeEndpoint;
    }

    public function getAccessTokenEndpoint(): ?string
    {
        return $this->accessTokenEndpoint;
    }

    public function setAccessTokenEndpoint(?string $accessTokenEndpoint): void
    {
        $this->accessTokenEndpoint = $accessTokenEndpoint;
    }

    public function getUserInfoEndpoint(): ?string
    {
        return $this->userInfoEndpoint;
    }

    public function setUserInfoEndpoint(?string $userInfoEndpoint): void
    {
        $this->userInfoEndpoint = $userInfoEndpoint;
    }

    public function getEndSessionEndpoint(): ?string
    {
        return $this->endSessionEndpoint;
    }

    public function setEndSessionEndpoint(?string $endSessionEndpoint): void
    {
        $this->endSessionEndpoint = $endSessionEndpoint;
    }

    public function getRevocationEndpoint(): ?string
    {
        return $this->revocationEndpoint;
    }

    public function setRevocationEndpoint(?string $revocationEndpoint): void
    {
        $this->revocationEndpoint = $revocationEndpoint;
    }

    public function getJwksEndpoint(): ?string
    {
        return $this->jwksEndpoint;
    }

    public function setJwksEndpoint(?string $jwksEndpoint): void
    {
        $this->jwksEndpoint = $jwksEndpoint;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    public function setIssuer(?string $issuer): void
    {
        $this->issuer = $issuer;
    }

    public function getWellKnownConfigUrl(): ?string
    {
        return $this->wellKnownConfigUrl;
    }

    public function setWellKnownConfigUrl(?string $wellKnownConfigUrl): void
    {
        $this->wellKnownConfigUrl = $wellKnownConfigUrl;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function getPkceFlow(): string
    {
        return $this->pkceFlow;
    }

    public function setPkceFlow(string $pkceFlow): void
    {
        $this->pkceFlow = $pkceFlow;
    }

    public function getClaimEncoding(): string
    {
        return $this->claimEncoding;
    }

    public function setClaimEncoding(string $claimEncoding): void
    {
        $this->claimEncoding = $claimEncoding;
    }

    public function isPublicClient(): bool
    {
        return $this->publicClient;
    }

    public function setPublicClient(bool $publicClient): void
    {
        $this->publicClient = $publicClient;
    }

    public function getGroupAttribute(): string
    {
        return $this->groupAttribute;
    }

    public function setGroupAttribute(string $groupAttribute): void
    {
        $this->groupAttribute = $groupAttribute;
    }

    public function isAutoCreateCustomer(): bool
    {
        return $this->autoCreateCustomer;
    }

    public function setAutoCreateCustomer(bool $autoCreateCustomer): void
    {
        $this->autoCreateCustomer = $autoCreateCustomer;
    }

    public function isAutoCreateAdmin(): bool
    {
        return $this->autoCreateAdmin;
    }

    public function setAutoCreateAdmin(bool $autoCreateAdmin): void
    {
        $this->autoCreateAdmin = $autoCreateAdmin;
    }

    public function isDisableNonOidcAdminLogin(): bool
    {
        return $this->disableNonOidcAdminLogin;
    }

    public function setDisableNonOidcAdminLogin(bool $disableNonOidcAdminLogin): void
    {
        $this->disableNonOidcAdminLogin = $disableNonOidcAdminLogin;
    }

    public function isDisableNonOidcCustomerLogin(): bool
    {
        return $this->disableNonOidcCustomerLogin;
    }

    public function setDisableNonOidcCustomerLogin(bool $disableNonOidcCustomerLogin): void
    {
        $this->disableNonOidcCustomerLogin = $disableNonOidcCustomerLogin;
    }

    public function isShowCustomerLink(): bool
    {
        return $this->showCustomerLink;
    }

    public function setShowCustomerLink(bool $showCustomerLink): void
    {
        $this->showCustomerLink = $showCustomerLink;
    }

    public function isShowAdminLink(): bool
    {
        return $this->showAdminLink;
    }

    public function setShowAdminLink(bool $showAdminLink): void
    {
        $this->showAdminLink = $showAdminLink;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getLoginType(): string
    {
        return $this->loginType;
    }

    public function setLoginType(string $loginType): void
    {
        $this->loginType = $loginType;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getButtonLabel(): ?string
    {
        return $this->buttonLabel;
    }

    public function setButtonLabel(?string $buttonLabel): void
    {
        $this->buttonLabel = $buttonLabel;
    }

    public function getButtonColor(): ?string
    {
        return $this->buttonColor;
    }

    public function setButtonColor(?string $buttonColor): void
    {
        $this->buttonColor = $buttonColor;
    }

    public function isSyncCustomerProfileOnSso(): bool
    {
        return $this->syncCustomerProfileOnSso;
    }

    public function setSyncCustomerProfileOnSso(bool $syncCustomerProfileOnSso): void
    {
        $this->syncCustomerProfileOnSso = $syncCustomerProfileOnSso;
    }

    public function isSyncCustomerAddressOnSso(): bool
    {
        return $this->syncCustomerAddressOnSso;
    }

    public function setSyncCustomerAddressOnSso(bool $syncCustomerAddressOnSso): void
    {
        $this->syncCustomerAddressOnSso = $syncCustomerAddressOnSso;
    }

    public function isSyncCustomerGroupOnSso(): bool
    {
        return $this->syncCustomerGroupOnSso;
    }

    public function setSyncCustomerGroupOnSso(bool $syncCustomerGroupOnSso): void
    {
        $this->syncCustomerGroupOnSso = $syncCustomerGroupOnSso;
    }

    public function isSyncAdminProfileOnSso(): bool
    {
        return $this->syncAdminProfileOnSso;
    }

    public function setSyncAdminProfileOnSso(bool $syncAdminProfileOnSso): void
    {
        $this->syncAdminProfileOnSso = $syncAdminProfileOnSso;
    }

    public function isSyncAdminRoleOnSso(): bool
    {
        return $this->syncAdminRoleOnSso;
    }

    public function setSyncAdminRoleOnSso(bool $syncAdminRoleOnSso): void
    {
        $this->syncAdminRoleOnSso = $syncAdminRoleOnSso;
    }

    public function getHttpTimeout(): int
    {
        return $this->httpTimeout;
    }

    public function setHttpTimeout(int $httpTimeout): void
    {
        $this->httpTimeout = $httpTimeout;
    }

    public function getJwksCacheTtl(): int
    {
        return $this->jwksCacheTtl;
    }

    public function setJwksCacheTtl(int $jwksCacheTtl): void
    {
        $this->jwksCacheTtl = $jwksCacheTtl;
    }

    public function getDefaultCustomerGroupId(): ?string
    {
        return $this->defaultCustomerGroupId;
    }

    public function setDefaultCustomerGroupId(?string $defaultCustomerGroupId): void
    {
        $this->defaultCustomerGroupId = $defaultCustomerGroupId;
    }

    public function getDefaultAclRoleId(): ?string
    {
        return $this->defaultAclRoleId;
    }

    public function setDefaultAclRoleId(?string $defaultAclRoleId): void
    {
        $this->defaultAclRoleId = $defaultAclRoleId;
    }

    public function getAttributeMappings(): ?Sw6OidcAttributeMappingCollection
    {
        return $this->attributeMappings;
    }

    public function setAttributeMappings(Sw6OidcAttributeMappingCollection $attributeMappings): void
    {
        $this->attributeMappings = $attributeMappings;
    }

    public function getRoleMappings(): ?Sw6OidcRoleMappingCollection
    {
        return $this->roleMappings;
    }

    public function setRoleMappings(Sw6OidcRoleMappingCollection $roleMappings): void
    {
        $this->roleMappings = $roleMappings;
    }

    public function getDefaultCustomerGroup(): ?CustomerGroupEntity
    {
        return $this->defaultCustomerGroup;
    }

    public function setDefaultCustomerGroup(?CustomerGroupEntity $defaultCustomerGroup): void
    {
        $this->defaultCustomerGroup = $defaultCustomerGroup;
    }

    public function getDefaultAclRole(): ?AclRoleEntity
    {
        return $this->defaultAclRole;
    }

    public function setDefaultAclRole(?AclRoleEntity $defaultAclRole): void
    {
        $this->defaultAclRole = $defaultAclRole;
    }
}
