<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Core\Content\AccessControlRule;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class Sw6OidcAccessControlRuleEntity extends Entity
{
    use EntityIdTrait;

    protected string $providerId;
    protected string $claimKey;
    /** one of Sw6OidcAccessControlRuleDefinition::OPERATORS */
    protected string $operator;
    protected ?string $value = null;
    protected ?string $errorMessage = null;
    protected int $sortOrder = 0;

    protected ?Sw6OidcProviderEntity $provider = null;

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getClaimKey(): string
    {
        return $this->claimKey;
    }

    public function setClaimKey(string $claimKey): void
    {
        $this->claimKey = $claimKey;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function setOperator(string $operator): void
    {
        $this->operator = $operator;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
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
}
