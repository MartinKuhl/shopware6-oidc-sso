<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\UserProvider;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class Sw6OidcUserProviderEntity extends Entity
{
    use EntityIdTrait;

    public const USER_TYPE_ADMIN = 'admin';
    public const USER_TYPE_CUSTOMER = 'customer';

    /** 'admin'|'customer' — the binding is polymorphic, so userId intentionally has no DAL association */
    protected string $userType;
    protected string $userId;
    protected string $providerId;

    protected ?Sw6OidcProviderEntity $provider = null;

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function setUserType(string $userType): void
    {
        $this->userType = $userType;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): void
    {
        $this->userId = $userId;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getProvider(): ?Sw6OidcProviderEntity
    {
        return $this->provider;
    }

    public function setProvider(?Sw6OidcProviderEntity $provider): void
    {
        $this->provider = $provider;
    }
}
