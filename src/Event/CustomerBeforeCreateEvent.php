<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Event;

use MartinKuhl\Sw6Oidc\Core\Content\Provider\Sw6OidcProviderEntity;
use MartinKuhl\Sw6Oidc\Service\Provisioning\MappedProfile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a customer is JIT-created from an OIDC login.
 * Listeners may change the customer.repository create payload (e.g. add
 * custom fields, tags, a different group); `id` is fixed and any change to
 * it is ignored.
 */
class CustomerBeforeCreateEvent extends Event implements ShopwareEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly Sw6OidcProviderEntity $provider,
        private readonly MappedProfile $profile,
        private array $payload,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    public function getProvider(): Sw6OidcProviderEntity
    {
        return $this->provider;
    }

    public function getProfile(): MappedProfile
    {
        return $this->profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }
}
