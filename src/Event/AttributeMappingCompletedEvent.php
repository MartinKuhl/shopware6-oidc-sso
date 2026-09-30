<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Event;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after AttributeMapper turned the (flattened) claims into a
 * MappedProfile, on every OIDC login — before any customer/admin is looked
 * up, created or synced. MappedProfile is immutable, so a listener replaces
 * it via setProfile(); the email is re-validated afterwards.
 */
class AttributeMappingCompletedEvent extends Event implements ShopwareEvent
{
    /**
     * @param array<string, mixed> $flattenedClaims
     */
    public function __construct(
        private readonly Sw6OidcProviderEntity $provider,
        private readonly array $flattenedClaims,
        private MappedProfile $profile,
        private readonly Context $context,
    ) {
    }

    public function getProvider(): Sw6OidcProviderEntity
    {
        return $this->provider;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFlattenedClaims(): array
    {
        return $this->flattenedClaims;
    }

    public function getProfile(): MappedProfile
    {
        return $this->profile;
    }

    public function setProfile(MappedProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
